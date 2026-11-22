<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F25: one charge to a consultant. Cancellation charges are added when the booking is cancelled;
 * absorbed fees and the plan charge by the monthly run. invoice_id is null until a monthly invoice
 * takes it. The amount is the one in force when it was made.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property int|null $invoice_id
 * @property string $type absorbed, plan, cancellation
 * @property int|null $source_id commitment_fee.id (absorbed) or appointments.id (cancellation)
 * @property int|null $facility_id
 * @property string $line_date Y-m-d
 * @property string $description
 * @property int $amount
 * @property int $waived
 * @property string|null $waive_reason
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Facility|null $facility
 * @property ConsultantInvoice|null $invoice
 */
class ConsultantInvoiceLine extends ActiveRecord
{
    const TYPE_ABSORBED = 'absorbed';
    const TYPE_PLAN = 'plan';
    const TYPE_CANCELLATION = 'cancellation';

    public static function tableName()
    {
        return '{{%consultant_invoice_line}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'consultant_invoice_line'],
        ];
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function getInvoice()
    {
        return $this->hasOne(ConsultantInvoice::class, ['id' => 'invoice_id']);
    }

    public static function typeLabels()
    {
        return [
            self::TYPE_ABSORBED => 'Absorbed commitment fee',
            self::TYPE_PLAN => 'Plan charge',
            self::TYPE_CANCELLATION => 'Cancellation charge',
        ];
    }
}
