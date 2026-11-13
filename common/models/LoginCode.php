<?php

namespace common\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * A one-time sign-in code sent to a phone. Only a hash of the code is stored. A code works once,
 * until it expires, for a few tries; sending a new one cancels the older ones. The rows also count
 * requests for the rate limits (per number and per IP address).
 *
 * @property int $id
 * @property string $phone normalized (see common\helpers\Phone)
 * @property string $code_hash
 * @property string $channel whatsapp|sms
 * @property int $attempts
 * @property int $expires_at
 * @property int|null $used_at
 * @property string|null $ip
 * @property int $created_at
 */
class LoginCode extends ActiveRecord
{
    const LENGTH = 6;

    public static function tableName()
    {
        return '{{%login_code}}';
    }

    /**
     * @param string $phone normalized
     * @return string|null why no code may be sent now ('phone' or 'ip'), or null when one may
     */
    public static function limitReached($phone, $ip)
    {
        $params = self::params();
        $now = time();
        $recent = self::find()->where(['phone' => $phone])->andWhere(['>', 'created_at', $now - 15 * 60])->count();
        $today = self::find()->where(['phone' => $phone])->andWhere(['>', 'created_at', $now - 86400])->count();
        if ($recent >= $params['perPhonePer15Minutes'] || $today >= $params['perPhonePerDay']) {
            return 'phone';
        }
        if ($ip !== null && $ip !== '') {
            $fromIp = self::find()->where(['ip' => $ip])->andWhere(['>', 'created_at', $now - 3600])->count();
            if ($fromIp >= $params['perIpPerHour']) {
                return 'ip';
            }
        }

        return null;
    }

    /**
     * Makes a new code for the phone, cancelling any earlier unused ones.
     *
     * @return array [the code (to send), the saved row]
     */
    public static function issue($phone, $channel, $ip)
    {
        $now = time();
        self::updateAll(['used_at' => $now], ['and', ['phone' => $phone], ['used_at' => null]]);

        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
        $row = new self([
            'phone' => $phone,
            'code_hash' => self::hash($phone, $code),
            'channel' => $channel,
            'attempts' => 0,
            'expires_at' => $now + 60 * self::params()['codeTtlMinutes'],
            'ip' => $ip !== null ? mb_substr((string) $ip, 0, 45) : null,
            'created_at' => $now,
        ]);
        $row->save(false);

        return [$code, $row];
    }

    /**
     * Checks a code typed for the phone against its latest code. A right code is used up; a wrong
     * one counts a try, and the code stops working after maxAttempts.
     *
     * @param string $phone normalized
     * @return string 'ok', 'wrong', 'expired' (expired, used up or too many tries) or 'none'
     */
    public static function check($phone, $code)
    {
        $row = self::find()->where(['phone' => $phone])->orderBy(['id' => SORT_DESC])->one();
        if ($row === null) {
            return 'none';
        }
        $now = time();
        if ($row->used_at !== null || $row->expires_at <= $now || $row->attempts >= self::params()['maxAttempts']) {
            return 'expired';
        }
        $code = preg_replace('/\D/', '', (string) $code);
        if (!hash_equals($row->code_hash, self::hash($phone, $code))) {
            $row->updateCounters(['attempts' => 1]);
            return $row->attempts >= self::params()['maxAttempts'] ? 'expired' : 'wrong';
        }
        // Used once: a second request with the same code fails
        $taken = self::updateAll(['used_at' => $now], ['id' => $row->id, 'used_at' => null]);

        return $taken ? 'ok' : 'expired';
    }

    /**
     * Rows older than a day are only needed for the daily limit; remove older ones.
     */
    public static function purge()
    {
        return self::deleteAll(['<', 'created_at', time() - 2 * 86400]);
    }

    public static function params()
    {
        return array_merge([
            'codeTtlMinutes' => 10,
            'maxAttempts' => 5,
            'perPhonePer15Minutes' => 3,
            'perPhonePerDay' => 10,
            'perIpPerHour' => 10,
            'resendAfterSeconds' => 60,
            'enabled' => true,
        ], Yii::$app->params['phoneSignIn'] ?? []);
    }

    private static function hash($phone, $code)
    {
        // Keyed, so a copy of the table does not give the codes away by trying all million
        $key = (string) (Yii::$app->params['selfService.key'] ?? '');
        if ($key === '') {
            $key = hash('sha256', 'login-code|' . Yii::$app->db->dsn . '|' . Yii::$app->db->password);
        }

        return hash_hmac('sha256', $phone . '|' . $code, $key);
    }
}
