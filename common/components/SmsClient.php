<?php

namespace common\components;

use common\helpers\Phone;
use Yii;

/**
 * Sends an SMS through the provider in params['sms'] (SMS_PROVIDER): Africa's Talking
 * ("africastalking", the usual choice in Kenya) or Twilio ("twilio").
 */
class SmsClient
{
    /**
     * Whether a provider is chosen and has its keys.
     */
    public static function isConfigured()
    {
        $config = Yii::$app->params['sms'] ?? [];
        switch ($config['provider'] ?? '') {
            case 'africastalking':
                $at = $config['africastalking'] ?? [];
                return !empty($at['username']) && !empty($at['api_key']);
            case 'twilio':
                $tw = $config['twilio'] ?? [];
                return !empty($tw['sid']) && !empty($tw['token']) && !empty($tw['from']);
        }

        return false;
    }

    /**
     * @param string $phone any format Phone::normalize() reads
     * @param string $text
     * @throws \RuntimeException when no provider is configured, the number cannot be read, or the
     * provider refuses the message
     */
    public static function send($phone, $text)
    {
        $config = Yii::$app->params['sms'] ?? [];
        $number = Phone::normalize($phone);
        if ($number === null) {
            throw new \RuntimeException('SMS not sent: the phone number cannot be read.');
        }
        switch ($config['provider'] ?? '') {
            case 'africastalking':
                self::sendAfricasTalking($config['africastalking'] ?? [], '+' . $number, $text);
                return;
            case 'twilio':
                self::sendTwilio($config['twilio'] ?? [], '+' . $number, $text);
                return;
        }

        throw new \RuntimeException('SMS provider not configured (SMS_PROVIDER).');
    }

    /**
     * @see https://developers.africastalking.com/docs/sms/sending/bulk
     */
    private static function sendAfricasTalking(array $config, $to, $text)
    {
        if (empty($config['username']) || empty($config['api_key'])) {
            throw new \RuntimeException("Africa's Talking SMS configuration missing (AT_USERNAME, AT_API_KEY).");
        }
        $host = $config['username'] === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
        $fields = ['username' => $config['username'], 'to' => $to, 'message' => $text];
        if (!empty($config['from'])) {
            $fields['from'] = $config['from'];
        }

        [$status, $body] = self::post($host . '/version1/messaging', http_build_query($fields), [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apiKey: ' . $config['api_key'],
        ]);
        $data = json_decode((string) $body, true);
        $recipient = $data['SMSMessageData']['Recipients'][0] ?? null;
        // 100 Processed, 101 Sent, 102 Queued
        if ($status !== 201 || !$recipient || !in_array((int) ($recipient['statusCode'] ?? 0), [100, 101, 102], true)) {
            throw new \RuntimeException("Africa's Talking refused the SMS (HTTP $status): " . mb_substr((string) $body, 0, 300));
        }
    }

    private static function sendTwilio(array $config, $to, $text)
    {
        if (empty($config['sid']) || empty($config['token']) || empty($config['from'])) {
            throw new \RuntimeException('Twilio SMS configuration missing (TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM).');
        }
        [$status, $body] = self::post(
            "https://api.twilio.com/2010-04-01/Accounts/{$config['sid']}/Messages.json",
            http_build_query(['From' => $config['from'], 'To' => $to, 'Body' => $text]),
            [],
            $config['sid'] . ':' . $config['token']
        );
        if ($status !== 201) {
            throw new \RuntimeException("Twilio refused the SMS (HTTP $status): " . mb_substr((string) $body, 0, 300));
        }
    }

    /**
     * @return array [HTTP status, body]
     */
    private static function post($url, $body, array $headers, $userPwd = null)
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($userPwd !== null) {
            $options[CURLOPT_USERPWD] = $userPwd;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            throw new \RuntimeException('SMS request failed: ' . $error);
        }

        return [$status, $result];
    }
}
