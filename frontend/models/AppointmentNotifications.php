<?php

namespace frontend\models;

use Yii;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "appointment_notifications".
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $notification_schedule_id
 * @property string $notification_type
 * @property int $minutes_before
 * @property string $status (pending, sent, failed)
 * @property string|null $scheduled_time
 * @property string|null $sent_at
 * @property string|null $error_message
 * @property int|null $created_at
 * @property int|null $updated_at
 */
class AppointmentNotifications extends \yii\db\ActiveRecord
{
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'appointment_notifications';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['appointment_id', 'notification_schedule_id', 'notification_type', 'minutes_before'], 'required'],
            [['appointment_id', 'notification_schedule_id', 'minutes_before', 'created_at', 'updated_at'], 'integer'],
            [['notification_type'], 'string', 'max' => 20],
            [['status'], 'string', 'max' => 20],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['scheduled_time', 'sent_at'], 'safe'],
            [['error_message'], 'string'],
        ];
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }

    public function getNotificationSchedule()
    {
        return $this->hasOne(NotificationSchedules::class, ['id' => 'notification_schedule_id']);
    }

    /**
     * Get pending notifications ready to be sent
     */
    public static function getPendingNotifications()
    {
        return self::find()
            ->where(['status' => self::STATUS_PENDING])
            ->andWhere(['<=', 'scheduled_time', date('Y-m-d H:i:s')])
            ->with(['appointment', 'notificationSchedule'])
            ->all();
    }

    /**
     * Mark notification as sent
     */
    public function markAsSent()
    {
        $this->status = self::STATUS_SENT;
        $this->sent_at = date('Y-m-d H:i:s');
        return $this->save(false);
    }

    /**
     * Mark notification as failed
     */
    public function markAsFailed($errorMessage = null)
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        return $this->save(false);
    }
}