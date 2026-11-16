<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F24: the Ksh 50 a front-desk user earns for a Plan A booking they made ahead (see Incentives).
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $fee_id
 * @property int $front_desk_id user.id
 * @property int $patient_id
 * @property int|null $facility_id
 * @property int $amount
 * @property string $state pending, earned, cancelled or paid
 * @property int $flagged a cancel-and-rebook of the same patient within 24 hours
 * @property int|null $earned_at
 * @property int|null $payout_id
 * @property string|null $note
 * @property int|null $created_at
 * @property int|null $updated_at
 *
 * @property Appointments $appointment
 * @property User $frontDesk
 */
class FrontdeskIncentive extends ActiveRecord
{
    const STATE_PENDING = 'pending';
    const STATE_EARNED = 'earned';
    const STATE_CANCELLED = 'cancelled';
    const STATE_PAID = 'paid';

    public static function tableName()
    {
        return '{{%frontdesk_incentive}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'frontdesk_incentive'],
        ];
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }

    public function getFrontDesk()
    {
        return $this->hasOne(User::class, ['id' => 'front_desk_id']);
    }

    public static function forAppointment($appointmentId)
    {
        return static::findOne(['appointment_id' => $appointmentId]);
    }

    public static function stateLabels()
    {
        return [
            self::STATE_PENDING => 'Pending (until the patient attends)',
            self::STATE_EARNED => 'Earned',
            self::STATE_CANCELLED => 'Cancelled',
            self::STATE_PAID => 'Paid',
        ];
    }
}
