<?php

namespace common\components;

use Yii;

/**
 * Sends a WhatsApp text through the WAHA server configured in params['whatsapp']
 * (WAHA_ENDPOINT, WAHA_SESSION, WAHA_API_KEY), the same set-up the reminder jobs use.
 */
class WahaClient
{
    /**
     * @param string $phone any Kenyan format (07…, 254…, +254…) or international digits
     * @param string $text
     * @throws \RuntimeException when WAHA is not configured or refuses the message
     */
    public static function sendText($phone, $text)
    {
        $config = Yii::$app->params['whatsapp'] ?? [];
        if (empty($config['endpoint'])) {
            throw new \RuntimeException('WAHA WhatsApp configuration missing (WAHA_ENDPOINT).');
        }

        $payload = [
            'chatId' => self::chatId($phone),
            'text' => $text,
            'session' => ($config['session'] ?? '') ?: 'default',
            'linkPreview' => false,
        ];
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($config['api_key'])) {
            $headers[] = 'X-Api-Key: ' . $config['api_key'];
        }

        $ch = curl_init(rtrim($config['endpoint'], '/') . '/api/sendText');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('WhatsApp request failed: ' . $error);
        }
        if ($status !== 200 && $status !== 201) {
            throw new \RuntimeException("WhatsApp message refused (HTTP $status): " . mb_substr((string) $result, 0, 300));
        }
    }

    /**
     * "0712 345 678" / "254712345678" / "+254712345678" -> "254712345678@c.us"
     */
    public static function chatId($phone)
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strpos($digits, '0') === 0) {
            $digits = '254' . substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '254' . $digits;
        }

        return $digits . '@c.us';
    }
}
