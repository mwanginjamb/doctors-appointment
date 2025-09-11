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
            [['notification_type'], 'in', 'range' => [self::TYPE_EMAIL, self::TYPE_SMS, self::TYPE_PUSH]],
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