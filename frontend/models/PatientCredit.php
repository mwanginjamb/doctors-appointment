<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use frontend\services\SelfServiceLink;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F23: a rebooking credit worth one commitment fee (see the migration for when one is made). It
 * pays the fee of the patient's next charged booking within 90 days. A refundable one can be
 * asked back by M-Pesa from its signed page, /credit/<id>/<signature>; a platform admin approves.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $amount
 * @property int $source_fee_id
 * @property string $reason clinic_cancelled, patient_cancelled or late_payment
 * @property int $refundable
 * @property string $status
 * @property int $expires_at
 * @property int|null $used_fee_id
 * @property int|null $used_at
 * @property int|null $refund_requested_at
 * @property string|null $refund_phone
 * @property int|null $refund_decided_by
 * @property int|null $refund_decided_at
 * @property int|null $refund_mpesa_transaction_id
 * @property string|null $refund_reference
 * @property int|null $refunded_at
 * @property string|null $note
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property CommitmentFee $sourceFee
 * @property User $patient
 */
class PatientCredit extends ActiveRecord
{
    const REASON_CLINIC_CANCELLED = 'clinic_cancelled';
    const REASON_PATIENT_CANCELLED = 'patient_cancelled';
    const REASON_LATE_PAYMENT = 'late_payment';

    const STATUS_AVAILABLE = 'available';
    const STATUS_USED = 'used';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REFUND_REQUESTED = 'refund_requested';
    const STATUS_REFUNDED = 'refunded';
    /** A rejected refund puts the credit back to use until it expires */
    const STATUS_REFUND_REJECTED = 'refund_rejected';

    const VALID_DAYS = 90;

    public static function tableName()
    {
        return '{{%patient_credit}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'patient_credit'],
        ];
    }

    public function getSourceFee()
    {
        return $this->hasOne(CommitmentFee::class, ['id' => 'source_fee_id']);
    }

    public function getPatient()
    {
        return $this->hasOne(User::class, ['id' => 'patient_id']);
    }

    /** Whether it can still pay a fee on $at: available (or refund rejected) and not expired. */
    public function isUsable($at = null)
    {
        return in_array($this->status, [self::STATUS_AVAILABLE, self::STATUS_REFUND_REJECTED], true)
            && (int) $this->expires_at > ($at ?? time());
    }

    /** Whether the patient may ask for it back now. */
    public function canRequestRefund($at = null)
    {
        return (bool) $this->refundable && $this->status === self::STATUS_AVAILABLE && (int) $this->expires_at > ($at ?? time());
    }

    public function signature()
    {
        return SelfServiceLink::sign('credit|' . $this->id . '|' . $this->patient_id . '|' . $this->source_fee_id);
    }

    /** Its page, where the patient sees it and asks for a refund. */
    public function url($absolute = false)
    {
        $path = '/credit/' . $this->id . '/' . $this->signature();

        return $absolute ? rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/') . $path : $path;
    }

    public static function statusLabels()
    {
        return [
            self::STATUS_AVAILABLE => 'Available',
            self::STATUS_USED => 'Used',
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_REFUND_REQUESTED => 'Refund requested',
            self::STATUS_REFUNDED => 'Refunded',
            self::STATUS_REFUND_REJECTED => 'Refund declined (still usable)',
        ];
    }

    public static function reasonLabels()
    {
        return [
            self::REASON_CLINIC_CANCELLED => 'The clinic cancelled',
            self::REASON_PATIENT_CANCELLED => 'Cancelled more than 24 hours ahead',
            self::REASON_LATE_PAYMENT => 'Paid after the time was taken',
        ];
    }
}
