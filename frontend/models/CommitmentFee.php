<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F22: the Ksh 250 commitment fee an appointment carries (docs/business-case.md), one per
 * appointment. The patient pays it by M-Pesa (payer patient), or the consultant absorbs it for an
 * insured patient and it is billed to them (payer consultant).
 *
 * States: pending (waiting for the patient's M-Pesa payment), paid or absorbed; failed when the
 * payment did not arrive; then, from F23, forfeited, transferred, credited, cancelled or refunded.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $patient_id
 * @property int $consultant_id user.id
 * @property int|null $facility_id
 * @property string $fee_date Y-m-d, the appointment's date
 * @property int $amount Ksh
 * @property string $payer patient or consultant
 * @property string $state
 * @property string|null $phone
 * @property int|null $mpesa_transaction_id
 * @property int|null $paid_at
 * @property int|null $settled_at
 * @property string|null $note
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Appointments $appointment
 */
class CommitmentFee extends ActiveRecord
{
    const PAYER_PATIENT = 'patient';
    const PAYER_CONSULTANT = 'consultant';

    const STATE_PENDING = 'pending';
    const STATE_PAID = 'paid';
    const STATE_ABSORBED = 'absorbed';
    const STATE_FAILED = 'failed';
    const STATE_FORFEITED = 'forfeited';
    const STATE_TRANSFERRED = 'transferred';
    const STATE_CREDITED = 'credited';
    const STATE_CANCELLED = 'cancelled';
    const STATE_REFUNDED = 'refunded';

    /** States in which the patient has paid, or is paying, that day's fee */
    const PATIENT_PAYING_STATES = [self::STATE_PENDING, self::STATE_PAID];

    public static function tableName()
    {
        return '{{%commitment_fee}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'commitment_fee'],
        ];
    }

    public function rules()
    {
        return [
            [['appointment_id', 'patient_id', 'consultant_id', 'fee_date', 'amount', 'payer', 'state'], 'required'],
            [['appointment_id', 'patient_id', 'consultant_id', 'facility_id', 'amount', 'mpesa_transaction_id'], 'integer'],
            [['payer'], 'in', 'range' => [self::PAYER_PATIENT, self::PAYER_CONSULTANT]],
            [['state'], 'in', 'range' => array_keys(self::stateLabels())],
            [['fee_date'], 'date', 'format' => 'php:Y-m-d'],
            [['phone'], 'string', 'max' => 15],
            [['note'], 'string', 'max' => 255],
        ];
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }

    public function getPatient()
    {
        return $this->hasOne(\common\models\User::class, ['id' => 'patient_id']);
    }

    /** The consultant's profile (consultant_id is their user id). */
    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['user_id' => 'consultant_id']);
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    /** The M-Pesa payment, with its receipt. */
    public function getTransaction()
    {
        return $this->hasOne(\common\models\MpesaTransaction::class, ['id' => 'mpesa_transaction_id']);
    }

    public static function forAppointment($appointmentId)
    {
        return static::findOne(['appointment_id' => $appointmentId]);
    }

    public static function stateLabels()
    {
        return [
            self::STATE_PENDING => 'Waiting for payment',
            self::STATE_PAID => 'Paid',
            self::STATE_ABSORBED => 'Absorbed by the consultant',
            self::STATE_FAILED => 'Not paid',
            self::STATE_FORFEITED => 'Forfeited',
            self::STATE_TRANSFERRED => 'Moved to the new time',
            self::STATE_CREDITED => 'Credited to the patient',
            self::STATE_CANCELLED => 'Cancelled',
            self::STATE_REFUNDED => 'Refunded',
        ];
    }
}
