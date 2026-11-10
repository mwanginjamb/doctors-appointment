<?php

namespace common\models;

use common\components\Audit;
use Yii;
use yii\db\ActiveRecord;

/**
 * One consent event (F11, Kenya Data Protection Act 2019): a patient granted or withdrew consent for
 * a purpose, under a version of the privacy notice, given online, verbally at the front desk, or
 * through a booking link. Rows are only added; the latest row for a purpose is the current answer.
 *
 * Purposes:
 *  - data: processing their personal and health data to book and run appointments (required to use
 *    the app);
 *  - reminders: reminders and notices by WhatsApp/SMS (optional; email is the account's channel).
 *
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property bool $granted
 * @property string $notice_version
 * @property string $method
 * @property int|null $recorded_by
 * @property string|null $ip
 * @property int $created_at
 */
class Consent extends ActiveRecord
{
    const PURPOSE_DATA = 'data';
    const PURPOSE_REMINDERS = 'reminders';

    const METHOD_ONLINE = 'online';
    const METHOD_FRONT_DESK = 'front_desk';
    const METHOD_BOOKING_LINK = 'booking_link';

    public static function tableName()
    {
        return '{{%consent}}';
    }

    public function beforeSave($insert)
    {
        // Append-only: the history is the proof
        return $insert && parent::beforeSave($insert);
    }

    public function beforeDelete()
    {
        return false;
    }

    /**
     * Whether consent is collected (consent.enabled, CONSENT_ENABLED in .env). When off, nobody is
     * asked or held on the consent page; recorded answers are kept, and an explicit "no" to
     * WhatsApp/SMS is still respected (remindersAllowed() works the same either way).
     */
    public static function isEnabled()
    {
        return (bool) (Yii::$app->params['consent.enabled'] ?? true);
    }

    /** The privacy notice version patients are asked to accept (privacy.version). */
    public static function noticeVersion()
    {
        return (string) (Yii::$app->params['privacy.version'] ?? '1');
    }

    /**
     * Records a patient's answer.
     *
     * @param int $userId the patient
     * @param string $purpose self::PURPOSE_*
     * @param bool $granted
     * @param string $method self::METHOD_*
     * @return self
     */
    public static function record($userId, $purpose, $granted, $method)
    {
        $user = Yii::$app->get('user', false);
        $staffId = $method === self::METHOD_FRONT_DESK && $user && !$user->isGuest ? (int) $user->id : null;
        $request = Yii::$app->get('request', false);

        $consent = new self([
            'user_id' => (int) $userId,
            'purpose' => $purpose,
            'granted' => (bool) $granted,
            'notice_version' => self::noticeVersion(),
            'method' => $method,
            'recorded_by' => $staffId,
            'ip' => $request instanceof \yii\web\Request ? $request->getUserIP() : null,
            'created_at' => time(),
        ]);
        $consent->save(false);

        Audit::log($granted ? AuditLog::ACTION_CONSENT_GRANTED : AuditLog::ACTION_CONSENT_WITHDRAWN, 'user', $userId, [],
            self::purposes()[$purpose] . ' · ' . self::methods()[$method] . ' · notice ' . $consent->notice_version);

        return $consent;
    }

    /**
     * Records the patient's answer for a purpose only if it differs from their current one.
     */
    public static function recordIfChanged($userId, $purpose, $granted, $method)
    {
        $latest = self::latest($userId, $purpose);
        if ($latest !== null && (bool) $latest->granted === (bool) $granted
            && ($purpose !== self::PURPOSE_DATA || $latest->notice_version === self::noticeVersion())) {
            return $latest;
        }
        return self::record($userId, $purpose, $granted, $method);
    }

    /** @return self|null the patient's current answer for a purpose */
    public static function latest($userId, $purpose)
    {
        return self::find()
            ->where(['user_id' => (int) $userId, 'purpose' => $purpose])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    /**
     * Consent to data processing under the current privacy notice: what a signed-in patient needs
     * to use the app.
     */
    public static function hasCurrentDataConsent($userId)
    {
        $latest = self::latest($userId, self::PURPOSE_DATA);
        return $latest !== null && $latest->granted && $latest->notice_version === self::noticeVersion();
    }

    /**
     * Consent to data processing under any notice version: what staff need before booking a
     * patient (a newer notice is shown to the patient when they next sign in).
     */
    public static function hasDataConsent($userId)
    {
        $latest = self::latest($userId, self::PURPOSE_DATA);
        return $latest !== null && (bool) $latest->granted;
    }

    /**
     * Whether WhatsApp/SMS reminders and notices may be sent to the patient.
     *
     * Opt-in: a patient who answered gets what they chose. Patients from before consent was recorded
     * (no answer yet) keep getting them until they answer ("legacy"). Never after consent to data
     * processing was withdrawn.
     */
    public static function remindersAllowed($userId)
    {
        $data = self::latest($userId, self::PURPOSE_DATA);
        if ($data !== null && !$data->granted) {
            return false;
        }
        $reminders = self::latest($userId, self::PURPOSE_REMINDERS);
        return $reminders === null ? true : (bool) $reminders->granted;
    }

    /**
     * Short status for staff screens.
     *
     * @return string 'given', 'old_notice', 'withdrawn' or 'none'
     */
    public static function status($userId)
    {
        $latest = self::latest($userId, self::PURPOSE_DATA);
        if ($latest === null) {
            return 'none';
        }
        if (!$latest->granted) {
            return 'withdrawn';
        }
        return $latest->notice_version === self::noticeVersion() ? 'given' : 'old_notice';
    }

    public static function statusLabels()
    {
        return [
            'given' => 'Consent given',
            'old_notice' => 'Consent given (earlier notice)',
            'withdrawn' => 'Consent withdrawn',
            'none' => 'Consent not recorded',
        ];
    }

    public static function purposes()
    {
        return [
            self::PURPOSE_DATA => 'Data processing',
            self::PURPOSE_REMINDERS => 'WhatsApp/SMS reminders',
        ];
    }

    public static function methods()
    {
        return [
            self::METHOD_ONLINE => 'online',
            self::METHOD_FRONT_DESK => 'verbally at the front desk',
            self::METHOD_BOOKING_LINK => 'through a booking link',
        ];
    }

    /** @return self[] a patient's consent history, newest first */
    public static function history($userId)
    {
        return self::find()->where(['user_id' => (int) $userId])->orderBy(['id' => SORT_DESC])->all();
    }

    public function getRecorder()
    {
        return $this->hasOne(User::class, ['id' => 'recorded_by']);
    }
}
