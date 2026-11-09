<?php

namespace frontend\models;

use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "notification_schedules".
 *
 * @property int $id
 * @property int $user_id
 * @property string $notification_type (email, sms, push)
 * @property int $minutes_before Minutes before appointment to send notification
 * @property int $is_active
 * @property string|null $notification_method (patient, consultant, both)
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class NotificationSchedules extends \yii\db\ActiveRecord
{
    const TYPE_EMAIL = 'email';
    const TYPE_SMS = 'sms';
    const TYPE_PUSH = 'push';

    const TYPE_META = 'whatsapp';

    const METHOD_PATIENT = 'patient';
    const METHOD_CONSULTANT = 'consultant';
    const METHOD_BOTH = 'both';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'notification_schedules';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'notification_type', 'minutes_before'], 'required'],
            [['user_id', 'minutes_before', 'is_active', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['notification_type'], 'string', 'max' => 20],
            [['notification_type'], 'in', 'range' => [self::TYPE_EMAIL, self::TYPE_SMS, self::TYPE_PUSH, self::TYPE_META]],
            [['notification_method'], 'string', 'max' => 20],
            [['notification_method'], 'in', 'range' => [self::METHOD_PATIENT, self::METHOD_CONSULTANT, self::METHOD_BOTH]],
            [['notification_method'], 'default', 'value' => self::METHOD_BOTH],
            [['is_active'], 'default', 'value' => 1],
            [['minutes_before'], 'integer', 'min' => 5], // Minimum 5 minutes notice
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'notification_type' => Yii::t('app', 'Notification Type'),
            'minutes_before' => Yii::t('app', 'Minutes Before'),
            'is_active' => Yii::t('app', 'Is Active'),
            'notification_method' => Yii::t('app', 'Notification Method'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_by' => Yii::t('app', 'Created By'),
            'updated_by' => Yii::t('app', 'Updated By'),
        ];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Get active notification schedules for a user
     */
    public static function getActiveSchedulesForUser($userId)
    {
        return self::find()
            ->where(['user_id' => $userId, 'is_active' => 1])
            ->orderBy(['minutes_before' => SORT_DESC])
            ->all();
    }

    /**
     * Reminder plan a patient receives, grouped by timing, for display.
     *
     * Uses the patient's own active schedules that reach patients (method patient/both). A patient
     * without any yet gets the configured defaults when their first appointment is booked
     * (see NotificationService::ensureDefaultSchedulesExist()), so those are shown instead.
     *
     * @param int $userId
     * @return array{isDefault: bool, items: array<int, array{when: string, channels: string[]}>}
     */
    public static function describeForPatient($userId)
    {
        $hasOwn = self::find()->where(['user_id' => $userId])->exists();
        if ($hasOwn) {
            $rows = self::find()
                ->select(['notification_type', 'minutes_before'])
                ->where([
                    'user_id' => $userId,
                    'is_active' => 1,
                    'notification_method' => [self::METHOD_PATIENT, self::METHOD_BOTH],
                ])
                ->asArray()
                ->all();
        } else {
            $rows = array_filter(Yii::$app->params['defaultNotificationSchedules'] ?? [], function ($s) {
                return !empty($s['is_active'])
                    && in_array($s['notification_method'] ?? self::METHOD_BOTH, [self::METHOD_PATIENT, self::METHOD_BOTH], true);
            });
        }

        $byMinutes = [];
        foreach ($rows as $row) {
            $label = self::channelLabel($row['notification_type']);
            $byMinutes[(int) $row['minutes_before']][$label] = $label;
        }
        krsort($byMinutes);

        $items = [];
        foreach ($byMinutes as $minutes => $channels) {
            $items[] = ['when' => self::humanizeMinutes($minutes) . ' before', 'channels' => array_values($channels)];
        }

        return ['isDefault' => !$hasOwn, 'items' => $items];
    }

    public static function channelLabel($type)
    {
        $labels = [
            self::TYPE_EMAIL => 'Email',
            self::TYPE_SMS => 'SMS',
            self::TYPE_PUSH => 'Push notification',
            self::TYPE_META => 'WhatsApp',
        ];
        return $labels[$type] ?? ucfirst((string) $type);
    }

    /**
     * 1440 => "1 day", 90 => "1 hour 30 minutes", 30 => "30 minutes"
     */
    public static function humanizeMinutes($minutes)
    {
        $minutes = (int) $minutes;
        $parts = [];
        foreach (['day' => 1440, 'hour' => 60, 'minute' => 1] as $unit => $size) {
            if ($minutes >= $size) {
                $n = intdiv($minutes, $size);
                $minutes -= $n * $size;
                $parts[] = $n . ' ' . $unit . ($n === 1 ? '' : 's');
            }
        }
        return $parts ? implode(' ', $parts) : '0 minutes';
    }

    /**
     * Get all unique notification times across all active schedules
     */
    public static function getAllActiveNotificationTimes()
    {
        return self::find()
            ->select(['minutes_before'])
            ->where(['is_active' => 1])
            ->distinct()
            ->column();
    }
}