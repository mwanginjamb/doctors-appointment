<?php

namespace common\components;

use common\models\MpesaTransaction;
use Yii;
use yii\base\Component;
use yii\helpers\Json;

/**
 * M-Pesa through Safaricom's Daraja API (T11): STK push (a patient pays the platform from their
 * phone), the STK status query, and B2C (the platform pays someone, for refunds and front-desk
 * payouts).
 *
 * Every account setting comes from params['mpesa'], which reads .env (MPESA_*). MPESA_ENV picks the
 * sandbox or the live API, so going live only changes .env.
 *
 * Safaricom answers each request later, by calling us back (frontend MpesaController). Callbacks
 * are not signed, so:
 *  - their address carries a secret (MPESA_CALLBACK_TOKEN), checked before anything is read;
 *  - a transaction is settled once: a repeated callback changes nothing;
 *  - an STK callback that reports success is checked with a status query before the payment counts.
 * When a callback never comes, `php yii mpesa/reconcile` settles pending STK payments by query.
 *
 * When a transaction reaches its final status, EVENT_SETTLED is raised with a {@see MpesaEvent}.
 */
class Mpesa extends Component
{
    const EVENT_SETTLED = 'settled';

    /** The STK query's answer while the customer has not responded yet. */
    const STILL_PROCESSING = '500.001.1001';

    /** @var string sandbox | production */
    public $env;
    public $consumerKey;
    public $consumerSecret;
    /** @var string the paybill (or till) number patients pay */
    public $shortcode;
    public $passkey;
    /** @var string CustomerPayBillOnline, or CustomerBuyGoodsOnline for a till */
    public $transactionType;
    /** @var string the public HTTPS address of this site, which Safaricom calls back */
    public $callbackUrl;
    /** @var string the secret in the callback addresses */
    public $callbackToken;
    public $b2cShortcode;
    public $initiatorName;
    public $securityCredential;
    /** @var string a CA bundle, only where PHP has none configured (some Windows set-ups) */
    public $caFile;

    /**
     * @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string}
     * Replaces the HTTP call; tests use it to answer for Safaricom.
     */
    public $transport;

    /** @var array|null the last status query's answer, for {@see lastQueryProblem()} */
    private $lastQuery;

    public function init()
    {
        parent::init();
        foreach (Yii::$app->params['mpesa'] ?? [] as $name => $value) {
            if (property_exists($this, $name) && $this->$name === null) {
                $this->$name = $value;
            }
        }
        $this->env = $this->env ?: 'sandbox';
        $this->transactionType = $this->transactionType ?: 'CustomerPayBillOnline';
        if (!$this->callbackUrl) {
            $this->callbackUrl = Yii::$app->params['app.publicUrl'] ?? '';
        }
    }

    public function isConfigured()
    {
        return $this->consumerKey && $this->consumerSecret && $this->shortcode && $this->passkey;
    }

    public function isB2cConfigured()
    {
        return $this->isConfigured() && $this->b2cShortcode && $this->initiatorName && $this->securityCredential;
    }

    public function baseUrl()
    {
        return $this->env === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * "0712 345 678", "712345678", "254712345678", "+254 712 345 678" -> "254712345678".
     * Safaricom numbers start 07 or 01.
     *
     * @throws \InvalidArgumentException when it is not a Kenyan mobile number
     */
    public static function normalisePhone($phone)
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '254' . substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '254' . $digits;
        }
        if (!preg_match('/^254[17]\d{8}$/', $digits)) {
            throw new \InvalidArgumentException('Not a Kenyan mobile number: ' . $phone);
        }

        return $digits;
    }

    /**
     * The callback address for one kind of answer: stk, b2c-result or b2c-timeout.
     */
    public function callbackAddress($kind)
    {
        if (!$this->callbackToken) {
            throw new MpesaException('MPESA_CALLBACK_TOKEN is not set.');
        }
        $base = rtrim((string) $this->callbackUrl, '/');
        if (strpos($base, 'https://') !== 0) {
            throw new MpesaException('MPESA_CALLBACK_URL must be the public https:// address of this site (a tunnel when testing locally).');
        }

        return $base . '/mpesa/' . $kind . '/' . $this->callbackToken;
    }

    /**
     * Whether a callback address's token is ours.
     */
    public function isCallbackToken($token)
    {
        return $this->callbackToken !== null && $this->callbackToken !== ''
            && is_string($token) && hash_equals((string) $this->callbackToken, $token);
    }

    // ---- Requests --------------------------------------------------------------------------

    /**
     * An OAuth token, kept in the cache until shortly before it expires (Safaricom gives an hour).
     */
    public function accessToken($refresh = false)
    {
        if (!$this->isConfigured()) {
            throw new MpesaException('M-Pesa is not configured (MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE, MPESA_PASSKEY).');
        }
        $key = ['mpesa.token', $this->env, $this->consumerKey];
        if (!$refresh && ($token = Yii::$app->cache->get($key))) {
            return $token;
        }

        [$status, $body] = $this->send('GET', $this->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials', [
            'Authorization: Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret),
        ]);
        $data = json_decode($body, true);
        if ($status !== 200 || empty($data['access_token'])) {
            throw new MpesaException("M-Pesa refused the consumer key and secret (HTTP $status).");
        }
        Yii::$app->cache->set($key, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3599) - 120));

        return $data['access_token'];
    }

    /**
     * Asks the patient's phone for a payment. The answer comes later, by callback.
     *
     * @param string $phone any Kenyan format
     * @param int $amount whole shillings
     * @param string $reference shown on the patient's prompt and statement (12 characters at most)
     * @param string $description why (13 characters at most are sent)
     * @param array $link ['type' => ..., 'id' => ...] what the money is for
     * @return MpesaTransaction pending when Safaricom accepted the request, failed when it refused
     * @throws MpesaException when Safaricom cannot be reached or M-Pesa is not configured
     */
    public function stkPush($phone, $amount, $reference, $description, array $link = [])
    {
        $transaction = new MpesaTransaction([
            'kind' => MpesaTransaction::KIND_STK,
            'phone' => self::normalisePhone($phone),
            'amount' => self::wholeShillings($amount),
            'reference' => self::clip($reference, 12),
            'description' => mb_substr(trim((string) $description), 0, 100),
            'link_type' => $link['type'] ?? null,
            'link_id' => $link['id'] ?? null,
        ]);
        $timestamp = self::timestamp();

        $data = $this->call('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $this->transactionType,
            'Amount' => $transaction->amount,
            'PartyA' => $transaction->phone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $transaction->phone,
            'CallBackURL' => $this->callbackAddress('stk'),
            'AccountReference' => $transaction->reference,
            'TransactionDesc' => self::clip($transaction->description, 13) ?: 'Payment',
        ]);

        if ((string) ($data['ResponseCode'] ?? '') === '0') {
            $transaction->merchant_request_id = $data['MerchantRequestID'] ?? null;
            $transaction->checkout_request_id = $data['CheckoutRequestID'] ?? null;
            $transaction->result_desc = $data['CustomerMessage'] ?? $data['ResponseDescription'] ?? null;
        } else {
            $transaction->status = MpesaTransaction::STATUS_FAILED;
            $transaction->result_code = (string) ($data['errorCode'] ?? $data['ResponseCode'] ?? '');
            $transaction->result_desc = self::clip($data['errorMessage'] ?? $data['ResponseDescription'] ?? 'Refused', 255);
            $transaction->settled_at = time();
        }
        $this->saveTransaction($transaction);
        if ($transaction->isSettled()) {
            $this->triggerSettled($transaction);
        }

        return $transaction;
    }

    /**
     * Safaricom's answer to "how did this STK push end?".
     *
     * @return array ResultCode / ResultDesc once the customer has answered, or errorCode
     *               STILL_PROCESSING while they have not
     */
    public function stkQuery($checkoutRequestId)
    {
        $timestamp = self::timestamp();

        return $this->lastQuery = $this->call('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);
    }

    /**
     * Why the last status query settled nothing: still waiting for the customer, or the error
     * Safaricom gave (such as its rate limit). Null when it gave a result.
     */
    public function lastQueryProblem()
    {
        $answer = $this->lastQuery;
        if (!is_array($answer) || isset($answer['ResultCode'])) {
            return null;
        }
        if (($answer['errorCode'] ?? null) === self::STILL_PROCESSING) {
            return 'still being processed (no answer from the customer yet)';
        }
        if (isset($answer['fault']['faultstring'])) {
            return 'Safaricom refused the query: ' . $answer['fault']['faultstring'];
        }

        return 'Safaricom answered: ' . ($answer['errorMessage'] ?? '') . ' ' . ($answer['errorCode'] ?? Json::encode($answer));
    }

    /**
     * Settles a pending STK transaction from a status query, for when the callback has not come.
     *
     * @return bool whether it is settled now
     */
    public function refreshStk(MpesaTransaction $transaction)
    {
        if ($transaction->kind !== MpesaTransaction::KIND_STK || !$transaction->isPending() || !$transaction->checkout_request_id) {
            return $transaction->isSettled();
        }
        $data = $this->stkQuery($transaction->checkout_request_id);
        if (!isset($data['ResultCode'])) {
            return false; // still waiting for the customer, or the query itself failed
        }

        $code = (string) $data['ResultCode'];
        // The query does not return the receipt number: take it from a callback that already came
        $receipt = $code === '0' ? self::callbackItem($transaction->callback, 'MpesaReceiptNumber') : null;
        $this->settle($transaction, self::stkStatus($code), $code, $data['ResultDesc'] ?? null, $receipt);

        return true;
    }

    /**
     * Pays someone from the B2C shortcode (refunds, front-desk payouts). The answer comes later, at
     * the b2c-result callback, or b2c-timeout when Safaricom's queue gave up.
     *
     * @param string $commandId BusinessPayment, SalaryPayment or PromotionPayment
     */
    public function b2cPayment($phone, $amount, $remarks, $occasion = '', array $link = [], $commandId = 'BusinessPayment')
    {
        if (!$this->isB2cConfigured()) {
            throw new MpesaException('M-Pesa B2C is not configured (MPESA_B2C_SHORTCODE, MPESA_INITIATOR_NAME, MPESA_SECURITY_CREDENTIAL).');
        }
        $transaction = new MpesaTransaction([
            'kind' => MpesaTransaction::KIND_B2C,
            'phone' => self::normalisePhone($phone),
            'amount' => self::wholeShillings($amount),
            'reference' => self::clip($occasion ?: 'Payout', 20),
            'description' => mb_substr(trim((string) $remarks), 0, 100) ?: 'Payout',
            'originator_conversation_id' => Yii::$app->security->generateRandomString(32),
            'link_type' => $link['type'] ?? null,
            'link_id' => $link['id'] ?? null,
        ]);

        $data = $this->call('/mpesa/b2c/v3/paymentrequest', [
            'OriginatorConversationID' => $transaction->originator_conversation_id,
            'InitiatorName' => $this->initiatorName,
            'SecurityCredential' => $this->securityCredential,
            'CommandID' => $commandId,
            'Amount' => $transaction->amount,
            'PartyA' => $this->b2cShortcode,
            'PartyB' => $transaction->phone,
            'Remarks' => self::clip($transaction->description, 100),
            'QueueTimeOutURL' => $this->callbackAddress('b2c-timeout'),
            'ResultURL' => $this->callbackAddress('b2c-result'),
            'Occasion' => $transaction->reference,
        ]);

        if ((string) ($data['ResponseCode'] ?? '') === '0') {
            $transaction->conversation_id = $data['ConversationID'] ?? null;
            $transaction->result_desc = $data['ResponseDescription'] ?? null;
        } else {
            $transaction->status = MpesaTransaction::STATUS_FAILED;
            $transaction->result_code = (string) ($data['errorCode'] ?? $data['ResponseCode'] ?? '');
            $transaction->result_desc = self::clip($data['errorMessage'] ?? $data['ResponseDescription'] ?? 'Refused', 255);
            $transaction->settled_at = time();
        }
        $this->saveTransaction($transaction);
        if ($transaction->isSettled()) {
            $this->triggerSettled($transaction);
        }

        return $transaction;
    }

    // ---- Callbacks -------------------------------------------------------------------------

    /**
     * Safaricom's answer to an STK push. A success counts only once a status query confirms it;
     * if the query cannot say yet, the transaction stays pending for `mpesa/reconcile`.
     *
     * @return MpesaTransaction|null null when the callback names no transaction of ours
     */
    public function handleStkCallback(array $payload)
    {
        $callback = $payload['Body']['stkCallback'] ?? null;
        $transaction = isset($callback['CheckoutRequestID'])
            ? MpesaTransaction::findOne(['kind' => MpesaTransaction::KIND_STK, 'checkout_request_id' => (string) $callback['CheckoutRequestID']])
            : null;
        if ($transaction === null) {
            Yii::warning('M-Pesa STK callback for an unknown request: ' . Json::encode($payload), __METHOD__);
            return null;
        }
        if ($transaction->isSettled()) {
            return $transaction; // already settled: a repeated callback changes nothing
        }

        $transaction->callback = Json::encode($payload);
        $code = (string) ($callback['ResultCode'] ?? '');
        if ($code !== '0') {
            $this->settle($transaction, self::stkStatus($code), $code, $callback['ResultDesc'] ?? null);
            return $transaction;
        }

        $paid = (int) self::callbackItem($transaction->callback, 'Amount');
        if ($paid !== (int) $transaction->amount) {
            $this->settle($transaction, MpesaTransaction::STATUS_FAILED, 'amount', "Callback reported KES $paid, expected KES {$transaction->amount}");
            return $transaction;
        }

        try {
            $query = $this->stkQuery($transaction->checkout_request_id);
        } catch (MpesaException $e) {
            Yii::warning('M-Pesa STK callback not confirmed yet: ' . $e->getMessage(), __METHOD__);
            $query = [];
        }
        if (!isset($query['ResultCode'])) {
            $this->saveTransaction($transaction); // keep the callback; mpesa/reconcile finishes it
            return $transaction;
        }
        if ((string) $query['ResultCode'] !== '0') {
            $this->settle($transaction, MpesaTransaction::STATUS_FAILED, (string) $query['ResultCode'],
                'Callback reported success, but the status query reported: ' . ($query['ResultDesc'] ?? $query['ResultCode']));
            return $transaction;
        }
        $this->settle($transaction, MpesaTransaction::STATUS_SUCCEEDED, '0', $callback['ResultDesc'] ?? null,
            self::callbackItem($transaction->callback, 'MpesaReceiptNumber'));

        return $transaction;
    }

    /**
     * Safaricom's answer to a B2C payment.
     */
    public function handleB2cResult(array $payload)
    {
        $result = $payload['Result'] ?? null;
        $transaction = $this->findB2c($result);
        if ($transaction === null || $transaction->isSettled()) {
            return $transaction;
        }

        $transaction->callback = Json::encode($payload);
        $transaction->conversation_id = $transaction->conversation_id ?: ($result['ConversationID'] ?? null);
        $code = (string) ($result['ResultCode'] ?? '');
        if ($code !== '0') {
            $this->settle($transaction, MpesaTransaction::STATUS_FAILED, $code, $result['ResultDesc'] ?? null);
            return $transaction;
        }

        $parameters = [];
        foreach ($result['ResultParameters']['ResultParameter'] ?? [] as $parameter) {
            if (isset($parameter['Key'])) {
                $parameters[$parameter['Key']] = $parameter['Value'] ?? null;
            }
        }
        if (isset($parameters['TransactionAmount']) && (int) $parameters['TransactionAmount'] !== (int) $transaction->amount) {
            $this->settle($transaction, MpesaTransaction::STATUS_FAILED, 'amount',
                "Result reported KES {$parameters['TransactionAmount']}, expected KES {$transaction->amount}");
            return $transaction;
        }
        $this->settle($transaction, MpesaTransaction::STATUS_SUCCEEDED, '0', $result['ResultDesc'] ?? null,
            $result['TransactionID'] ?? $parameters['TransactionReceipt'] ?? null);

        return $transaction;
    }

    /**
     * Safaricom's queue gave up on a B2C payment before processing it.
     */
    public function handleB2cTimeout(array $payload)
    {
        $transaction = $this->findB2c($payload['Result'] ?? $payload);
        if ($transaction === null || $transaction->isSettled()) {
            return $transaction;
        }
        $transaction->callback = Json::encode($payload);
        $this->settle($transaction, MpesaTransaction::STATUS_TIMED_OUT, 'timeout', 'Safaricom timed out before processing the payment');

        return $transaction;
    }

    // ---- Internals -------------------------------------------------------------------------

    /**
     * The final status for an STK result code.
     */
    public static function stkStatus($code)
    {
        switch ((string) $code) {
            case '0':
                return MpesaTransaction::STATUS_SUCCEEDED;
            case '1032': // the customer cancelled the prompt
                return MpesaTransaction::STATUS_CANCELLED;
            case '1037': // the phone could not be reached
            case '1019': // the prompt expired
                return MpesaTransaction::STATUS_TIMED_OUT;
            default: // 1: not enough money; 2001: wrong PIN; others
                return MpesaTransaction::STATUS_FAILED;
        }
    }

    private function settle(MpesaTransaction $transaction, $status, $code, $description = null, $receipt = null)
    {
        $transaction->status = $status;
        $transaction->result_code = $code === null ? null : (string) $code;
        $transaction->result_desc = $description === null ? null : self::clip($description, 255);
        if ($receipt) {
            $transaction->receipt = (string) $receipt;
        }
        $transaction->settled_at = time();
        $this->saveTransaction($transaction);
        $this->triggerSettled($transaction);
    }

    private function triggerSettled(MpesaTransaction $transaction)
    {
        $this->trigger(self::EVENT_SETTLED, new MpesaEvent(['transaction' => $transaction]));
    }

    private function saveTransaction(MpesaTransaction $transaction)
    {
        if (!$transaction->save()) {
            throw new MpesaException('Could not save the M-Pesa transaction: ' . Json::encode($transaction->errors));
        }
    }

    private function findB2c($result)
    {
        $id = is_array($result) ? ($result['OriginatorConversationID'] ?? null) : null;
        $transaction = $id === null ? null : MpesaTransaction::findOne([
            'kind' => MpesaTransaction::KIND_B2C, 'originator_conversation_id' => (string) $id,
        ]);
        if ($transaction === null) {
            Yii::warning('M-Pesa B2C callback for an unknown request: ' . Json::encode($result), __METHOD__);
        }

        return $transaction;
    }

    /**
     * A value from an STK callback's CallbackMetadata (Amount, MpesaReceiptNumber, PhoneNumber…).
     */
    private static function callbackItem($json, $name)
    {
        $payload = $json ? json_decode($json, true) : null;
        foreach ($payload['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [] as $item) {
            if (($item['Name'] ?? null) === $name) {
                return $item['Value'] ?? null;
            }
        }

        return null;
    }

    private function password($timestamp)
    {
        return base64_encode($this->shortcode . $this->passkey . $timestamp);
    }

    private static function timestamp()
    {
        return (new \DateTime('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');
    }

    private static function wholeShillings($amount)
    {
        if (!is_numeric($amount) || (int) $amount != $amount || (int) $amount < 1) {
            throw new \InvalidArgumentException('M-Pesa amounts are whole shillings of at least 1: ' . $amount);
        }

        return (int) $amount;
    }

    private static function clip($text, $length)
    {
        return rtrim(mb_substr(trim(preg_replace('/\s+/', ' ', (string) $text)), 0, $length));
    }

    /**
     * POSTs JSON to Daraja with the access token. A 401 means the cached token expired early:
     * get a new one and try once more.
     */
    private function call($path, array $body)
    {
        $json = Json::encode($body);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$status, $response] = $this->send('POST', $this->baseUrl() . $path, [
                'Authorization: Bearer ' . $this->accessToken($attempt > 0),
                'Content-Type: application/json',
            ], $json);
            if ($status !== 401) {
                break;
            }
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new MpesaException("M-Pesa answered HTTP $status with no JSON: " . mb_substr($response, 0, 200));
        }

        return $data;
    }

    private function send($method, $url, array $headers, $body = null)
    {
        if ($this->transport !== null) {
            return call_user_func($this->transport, $method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->caFile) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            throw new MpesaException('M-Pesa cannot be reached: ' . $error);
        }

        return [$status, (string) $response];
    }
}
