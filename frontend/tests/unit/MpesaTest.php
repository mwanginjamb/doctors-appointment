<?php

namespace frontend\tests\unit;

use common\components\Mpesa;
use common\components\MpesaEvent;
use common\components\MpesaException;
use common\models\MpesaTransaction;
use Yii;
use yii\base\Event;
use yii\helpers\Json;

/**
 * T11 M-Pesa (Daraja). Safaricom is played by a fake transport: each test lists the answers it
 * gives, and every request it received is kept in $this->requests.
 */
class MpesaTest extends \Codeception\Test\Unit
{
    /** @var array<string, array[]> path => queue of [status, body] */
    private $answers = [];
    /** @var array[] each request: ['method', 'path', 'headers', 'body' (decoded)] */
    private $requests = [];
    /** @var MpesaTransaction[] */
    private $settled = [];

    protected function _before()
    {
        Yii::$app->cache->flush();
        MpesaTransaction::deleteAll();
        Event::on(Mpesa::class, Mpesa::EVENT_SETTLED, [$this, 'recordSettled']);
    }

    protected function _after()
    {
        Event::off(Mpesa::class, Mpesa::EVENT_SETTLED, [$this, 'recordSettled']);
    }

    public function recordSettled(MpesaEvent $event)
    {
        $this->settled[] = $event->transaction;
    }

    private function mpesa(array $config = [])
    {
        return new Mpesa($config + [
            'env' => 'sandbox',
            'consumerKey' => 'key',
            'consumerSecret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'pass',
            'callbackUrl' => 'https://appointments.example.test/',
            'callbackToken' => 'callback-token-0123456789',
            'b2cShortcode' => '600000',
            'initiatorName' => 'testapi',
            'securityCredential' => 'credential',
            'transport' => function ($method, $url, $headers, $body) {
                $path = parse_url($url, PHP_URL_PATH);
                $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body === null ? null : Json::decode($body)];
                if (empty($this->answers[$path])) {
                    throw new MpesaException("No answer prepared for $path");
                }

                return array_shift($this->answers[$path]);
            },
        ]);
    }

    private function answer($path, array $body, $status = 200)
    {
        $this->answers[$path][] = [$status, Json::encode($body)];
    }

    private function answerToken()
    {
        $this->answer('/oauth/v1/generate', ['access_token' => 'token-1', 'expires_in' => '3599']);
    }

    private function answerStkAccepted($checkout = 'ws_CO_1')
    {
        $this->answer('/mpesa/stkpush/v1/processrequest', [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => $checkout,
            'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]);
    }

    private function answerQuery($code, $description = 'done')
    {
        $this->answer('/mpesa/stkpushquery/v1/query', ['ResultCode' => (string) $code, 'ResultDesc' => $description]);
    }

    private function requestsTo($path)
    {
        return array_values(array_filter($this->requests, function ($r) use ($path) {
            return $r['path'] === $path;
        }));
    }

    private function pushed(Mpesa $mpesa, $amount = 250)
    {
        $this->answerToken();
        $this->answerStkAccepted();

        return $mpesa->stkPush('0712 345 678', $amount, 'HealthFirst booking 42', 'Commitment fee');
    }

    private static function stkCallback($code, array $items = [], $checkout = 'ws_CO_1')
    {
        $callback = ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => $checkout, 'ResultCode' => $code, 'ResultDesc' => "Result $code"];
        if ($items) {
            $callback['CallbackMetadata'] = ['Item' => array_map(function ($name, $value) {
                return ['Name' => $name, 'Value' => $value];
            }, array_keys($items), $items)];
        }

        return ['Body' => ['stkCallback' => $callback]];
    }

    private static function paidCallback($amount = 250)
    {
        return self::stkCallback(0, ['Amount' => $amount, 'MpesaReceiptNumber' => 'TKF1ABC2DE', 'TransactionDate' => 20261115120000, 'PhoneNumber' => 254712345678]);
    }

    // ---- Phone numbers -----------------------------------------------------------------------

    public function testPhoneNumbersInAnyKenyanFormat()
    {
        foreach (['0712345678', '0712 345 678', '712345678', '254712345678', '+254 712 345 678', '0110123456'] as $phone) {
            $this->assertMatchesRegularExpression('/^254[17]\d{8}$/', Mpesa::normalisePhone($phone), $phone);
        }
        $this->assertSame('254712345678', Mpesa::normalisePhone('+254-712-345-678'));
        $this->assertSame('254110123456', Mpesa::normalisePhone('0110123456'));
    }

    public function testOtherNumbersAreRefused()
    {
        foreach (['', '0212345678', '07123', '+44 7700 900123', '2547123456789'] as $phone) {
            try {
                Mpesa::normalisePhone($phone);
                $this->fail("accepted '$phone'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Kenyan mobile', $e->getMessage());
            }
        }
    }

    // ---- STK push ----------------------------------------------------------------------------

    public function testStkPushAsksTheRightThing()
    {
        $transaction = $this->pushed($this->mpesa());

        $this->assertSame(MpesaTransaction::STATUS_PENDING, $transaction->status);
        $this->assertSame('ws_CO_1', $transaction->checkout_request_id);
        $this->assertNotNull($transaction->id);

        $token = $this->requestsTo('/oauth/v1/generate')[0];
        $this->assertContains('Authorization: Basic ' . base64_encode('key:secret'), $token['headers']);

        $push = $this->requestsTo('/mpesa/stkpush/v1/processrequest')[0];
        $this->assertContains('Authorization: Bearer token-1', $push['headers']);
        $body = $push['body'];
        $this->assertSame('174379', $body['BusinessShortCode']);
        $this->assertSame(base64_encode('174379' . 'pass' . $body['Timestamp']), $body['Password']);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $body['Timestamp']);
        $this->assertSame('CustomerPayBillOnline', $body['TransactionType']);
        $this->assertSame(250, $body['Amount']);
        $this->assertSame('254712345678', $body['PartyA']);
        $this->assertSame('254712345678', $body['PhoneNumber']);
        $this->assertSame('174379', $body['PartyB']);
        $this->assertSame('https://appointments.example.test/mpesa/stk/callback-token-0123456789', $body['CallBackURL']);
        $this->assertSame('HealthFirst', $body['AccountReference'], 'clipped to 12 characters, then trimmed');
        $this->assertLessThanOrEqual(13, mb_strlen($body['TransactionDesc']));
        $this->assertSame([], $this->settled, 'not settled until Safaricom answers');
    }

    public function testTheAccessTokenIsReused()
    {
        $mpesa = $this->mpesa();
        $this->pushed($mpesa);
        $this->answerStkAccepted('ws_CO_2');
        $mpesa->stkPush('0712345678', 100, 'Ref', 'Second');

        $this->assertCount(1, $this->requestsTo('/oauth/v1/generate'));
    }

    public function testAnExpiredTokenIsRenewedOnce()
    {
        $mpesa = $this->mpesa();
        $this->answerToken();
        $this->answer('/mpesa/stkpush/v1/processrequest', ['errorCode' => '404.001.03', 'errorMessage' => 'Invalid Access Token'], 401);
        $this->answer('/oauth/v1/generate', ['access_token' => 'token-2', 'expires_in' => '3599']);
        $this->answerStkAccepted();

        $transaction = $mpesa->stkPush('0712345678', 250, 'Ref', 'Fee');

        $this->assertSame(MpesaTransaction::STATUS_PENDING, $transaction->status);
        $pushes = $this->requestsTo('/mpesa/stkpush/v1/processrequest');
        $this->assertContains('Authorization: Bearer token-2', $pushes[1]['headers']);
    }

    public function testARefusedPushIsRecordedAsFailed()
    {
        $this->answerToken();
        $this->answer('/mpesa/stkpush/v1/processrequest', ['requestId' => 'r', 'errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber'], 400);

        $transaction = $this->mpesa()->stkPush('0712345678', 250, 'Ref', 'Fee');

        $this->assertSame(MpesaTransaction::STATUS_FAILED, $transaction->status);
        $this->assertSame('400.002.02', $transaction->result_code);
        $this->assertStringContainsString('Invalid PhoneNumber', $transaction->result_desc);
        $this->assertCount(1, $this->settled);
    }

    public function testAmountsAreWholeShillings()
    {
        foreach ([0, -5, 12.5, 'abc'] as $amount) {
            try {
                $this->mpesa()->stkPush('0712345678', $amount, 'Ref', 'Fee');
                $this->fail("accepted $amount");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('whole shillings', $e->getMessage());
            }
        }
        $this->assertSame([], $this->requests, 'nothing sent');
    }

    public function testAPushNeedsAnHttpsCallbackAndAToken()
    {
        $this->expectException(MpesaException::class);
        $this->mpesa(['callbackUrl' => 'http://localhost'])->callbackAddress('stk');
    }

    // ---- STK callbacks -----------------------------------------------------------------------

    public function testAPaymentCountsOnceTheQueryConfirmsIt()
    {
        $mpesa = $this->mpesa();
        $transaction = $this->pushed($mpesa);
        $this->answerQuery(0, 'The service request is processed successfully.');

        $result = $mpesa->handleStkCallback(self::paidCallback());

        $this->assertSame($transaction->id, $result->id);
        $result->refresh();
        $this->assertSame(MpesaTransaction::STATUS_SUCCEEDED, $result->status);
        $this->assertSame('TKF1ABC2DE', $result->receipt);
        $this->assertNotNull($result->settled_at);
        $this->assertCount(1, $this->settled);
        $this->assertSame('ws_CO_1', $this->requestsTo('/mpesa/stkpushquery/v1/query')[0]['body']['CheckoutRequestID']);
    }

    public function testARepeatedCallbackChangesNothing()
    {
        $mpesa = $this->mpesa();
        $this->pushed($mpesa);
        $this->answerQuery(0);
        $mpesa->handleStkCallback(self::paidCallback());

        $again = $mpesa->handleStkCallback(self::stkCallback(1032));

        $this->assertSame(MpesaTransaction::STATUS_SUCCEEDED, $again->status);
        $this->assertCount(1, $this->settled, 'settled once');
        $this->assertCount(1, $this->requestsTo('/mpesa/stkpushquery/v1/query'));
    }

    public function testASuccessTheQueryDeniesIsAFailure()
    {
        $mpesa = $this->mpesa();
        $this->pushed($mpesa);
        $this->answerQuery(1032, 'Request cancelled by user');

        $result = $mpesa->handleStkCallback(self::paidCallback());

        $this->assertSame(MpesaTransaction::STATUS_FAILED, $result->status);
        $this->assertNull($result->receipt);
        $this->assertStringContainsString('status query', $result->result_desc);
    }

    public function testAWrongAmountIsAFailure()
    {
        $mpesa = $this->mpesa();
        $this->pushed($mpesa, 250);

        $result = $mpesa->handleStkCallback(self::paidCallback(1));

        $this->assertSame(MpesaTransaction::STATUS_FAILED, $result->status);
        $this->assertStringContainsString('expected KES 250', $result->result_desc);
        $this->assertSame([], $this->requestsTo('/mpesa/stkpushquery/v1/query'));
    }

    public function testCancelledAndUnansweredPrompts()
    {
        $mpesa = $this->mpesa();
        $cases = ['1032' => MpesaTransaction::STATUS_CANCELLED, '1037' => MpesaTransaction::STATUS_TIMED_OUT, '1' => MpesaTransaction::STATUS_FAILED, '2001' => MpesaTransaction::STATUS_FAILED];
        $i = 0;
        foreach ($cases as $code => $status) {
            $checkout = 'ws_CO_case' . (++$i);
            $this->answerToken();
            $this->answerStkAccepted($checkout);
            $mpesa->stkPush('0712345678', 250, 'Ref', 'Fee');

            $result = $mpesa->handleStkCallback(self::stkCallback((int) $code, [], $checkout));

            $this->assertSame($status, $result->status, "result code $code");
            $this->assertSame((string) $code, $result->result_code);
        }
        $this->assertSame([], $this->requestsTo('/mpesa/stkpushquery/v1/query'), 'only a success is checked');
    }

    public function testACallbackForSomethingElseIsIgnored()
    {
        $mpesa = $this->mpesa();
        $this->pushed($mpesa);

        $this->assertNull($mpesa->handleStkCallback(self::stkCallback(0, [], 'ws_CO_unknown')));
        $this->assertNull($mpesa->handleStkCallback(['nonsense' => true]));
        $this->assertSame(MpesaTransaction::STATUS_PENDING, MpesaTransaction::find()->one()->status);
    }

    public function testASuccessTheQueryCannotConfirmYetIsSettledLater()
    {
        $mpesa = $this->mpesa();
        $transaction = $this->pushed($mpesa);
        $this->answer('/mpesa/stkpushquery/v1/query', ['errorCode' => Mpesa::STILL_PROCESSING, 'errorMessage' => 'The transaction is being processed'], 500);

        $result = $mpesa->handleStkCallback(self::paidCallback());
        $this->assertSame(MpesaTransaction::STATUS_PENDING, $result->status, 'waits for confirmation');
        $this->assertNotNull($result->callback);

        // mpesa/reconcile, later
        $this->answerQuery(0);
        $this->assertTrue($mpesa->refreshStk(MpesaTransaction::findOne($transaction->id)));
        $settled = MpesaTransaction::findOne($transaction->id);
        $this->assertSame(MpesaTransaction::STATUS_SUCCEEDED, $settled->status);
        $this->assertSame('TKF1ABC2DE', $settled->receipt, 'the receipt from the earlier callback');
    }

    public function testACallbackAfterTheQueryStillGivesTheReceipt()
    {
        // As on production: the status query settled the payment before Safaricom's callback came
        $mpesa = $this->mpesa();
        $transaction = $this->pushed($mpesa);
        $this->answerQuery(0);
        $this->assertTrue($mpesa->refreshStk($transaction));
        $this->assertNull(MpesaTransaction::findOne($transaction->id)->receipt, 'the query carries no receipt');

        $result = $mpesa->handleStkCallback(self::paidCallback());

        $this->assertSame(MpesaTransaction::STATUS_SUCCEEDED, $result->status);
        $this->assertSame('TKF1ABC2DE', MpesaTransaction::findOne($transaction->id)->receipt);
        $this->assertCount(1, $this->settled, 'settled once, not again');
        $this->assertCount(1, $this->requestsTo('/mpesa/stkpushquery/v1/query'), 'no second query');

        // A callback with another amount is not taken as this payment's receipt
        $this->answerStkAccepted('ws_CO_2');
        $other = $mpesa->stkPush('0712345678', 250, 'Ref', 'Fee');
        $this->answerQuery(0);
        $mpesa->refreshStk($other);
        $mpesa->handleStkCallback(self::stkCallback(0, ['Amount' => 1, 'MpesaReceiptNumber' => 'OTHER123'], 'ws_CO_2'));
        $this->assertNull(MpesaTransaction::findOne($other->id)->receipt);
    }

    public function testAQueryAloneSettlesAPushWhoseCallbackNeverCame()
    {
        $mpesa = $this->mpesa();
        $transaction = $this->pushed($mpesa);

        $this->answer('/mpesa/stkpushquery/v1/query', ['errorCode' => Mpesa::STILL_PROCESSING, 'errorMessage' => 'The transaction is being processed'], 500);
        $this->assertFalse($mpesa->refreshStk($transaction), 'the customer has not answered yet');

        $this->answerQuery(1037, 'DS timeout user cannot be reached');
        $this->assertTrue($mpesa->refreshStk($transaction));
        $this->assertSame(MpesaTransaction::STATUS_TIMED_OUT, MpesaTransaction::findOne($transaction->id)->status);
    }

    // ---- B2C ---------------------------------------------------------------------------------

    private function paidOut(Mpesa $mpesa, $amount = 50)
    {
        $this->answerToken();
        $this->answer('/mpesa/b2c/v3/paymentrequest', [
            'ConversationID' => 'AG_1', 'OriginatorConversationID' => 'echo',
            'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.',
        ]);

        return $mpesa->b2cPayment('0712345678', $amount, 'Front-desk incentives, week 46', 'Payout', ['type' => 'payout', 'id' => 7]);
    }

    public function testB2cPaysFromTheB2cShortcode()
    {
        $transaction = $this->paidOut($this->mpesa());

        $this->assertSame(MpesaTransaction::STATUS_PENDING, $transaction->status);
        $this->assertSame('payout', $transaction->link_type);
        $body = $this->requestsTo('/mpesa/b2c/v3/paymentrequest')[0]['body'];
        $this->assertSame($transaction->originator_conversation_id, $body['OriginatorConversationID']);
        $this->assertSame('testapi', $body['InitiatorName']);
        $this->assertSame('credential', $body['SecurityCredential']);
        $this->assertSame('BusinessPayment', $body['CommandID']);
        $this->assertSame('600000', $body['PartyA']);
        $this->assertSame('254712345678', $body['PartyB']);
        $this->assertSame(50, $body['Amount']);
        $this->assertSame('https://appointments.example.test/mpesa/b2c-result/callback-token-0123456789', $body['ResultURL']);
        $this->assertSame('https://appointments.example.test/mpesa/b2c-timeout/callback-token-0123456789', $body['QueueTimeOutURL']);
    }

    public function testB2cResults()
    {
        $mpesa = $this->mpesa();
        $paid = $this->paidOut($mpesa);
        $result = $mpesa->handleB2cResult(['Result' => [
            'ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'OriginatorConversationID' => $paid->originator_conversation_id, 'ConversationID' => 'AG_1', 'TransactionID' => 'TKF9XYZ',
            'ResultParameters' => ['ResultParameter' => [['Key' => 'TransactionAmount', 'Value' => 50], ['Key' => 'TransactionReceipt', 'Value' => 'TKF9XYZ']]],
        ]]);
        $this->assertSame(MpesaTransaction::STATUS_SUCCEEDED, $result->status);
        $this->assertSame('TKF9XYZ', $result->receipt);

        $refused = $this->paidOut($mpesa);
        $result = $mpesa->handleB2cResult(['Result' => [
            'ResultCode' => 2001, 'ResultDesc' => 'The initiator information is invalid.',
            'OriginatorConversationID' => $refused->originator_conversation_id,
        ]]);
        $this->assertSame(MpesaTransaction::STATUS_FAILED, $result->status);

        $late = $this->paidOut($mpesa);
        $result = $mpesa->handleB2cTimeout(['Result' => ['OriginatorConversationID' => $late->originator_conversation_id]]);
        $this->assertSame(MpesaTransaction::STATUS_TIMED_OUT, $result->status);

        $this->assertCount(3, $this->settled);
    }

    public function testB2cNeedsItsSettings()
    {
        $this->expectException(MpesaException::class);
        $this->mpesa(['securityCredential' => ''])->b2cPayment('0712345678', 50, 'Payout');
    }
}
