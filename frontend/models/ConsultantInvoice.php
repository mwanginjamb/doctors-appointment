<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use frontend\services\SelfServiceLink;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F25: a consultant's monthly invoice, across all their facilities (see frontend\services\Invoices).
 * During the pilot it is a statement of absorbed fees; from day 91 it also carries the plan charge
 * and cancellation charges. The consultant sees it at /invoice/<id>/<signature>.
 *
 * @property int $id
 * @property string $number
 * @property int $consultant_id consultant.id
 * @property string $period_start Y-m-d
 * @property string $period_end Y-m-d
 * @property int $amount Ksh, lines not waived
 * @property string $status issued, paid, void
 * @property int $pausable
 * @property int $issued_at
 * @property string $due_on Y-m-d
 * @property int|null $paid_at
 * @property int|null $paid_by
 * @property string|null $reference
 * @property int|null $mpesa_transaction_id
 * @property string|null $void_reason
 * @property int|null $voided_at
 * @property int|null $voided_by
 * @property int|null $reminded_at
 * @property int $reminders_sent part 2: 0, 1 (the due date) or 2 (a week later)
 * @property string|null $pay_phone part 2: where the last M-Pesa prompt went
 * @property string|null $pay_note part 2: why the last prompt did not go through
 * @property int|null $created_at
 * @property int|null $updated_at
 *
 * @property Consultant $consultant
 * @property ConsultantInvoiceLine[] $lines
 */
class ConsultantInvoice extends ActiveRecord
{
    const STATUS_ISSUED = 'issued';
    const STATUS_PAID = 'paid';
    const STATUS_VOID = 'void';

    public static function tableName()
    {
        return '{{%consultant_invoice}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'consultant_invoice'],
        ];
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    /** All its lines, waived ones included, by date. */
    public function getLines()
    {
        return $this->hasMany(ConsultantInvoiceLine::class, ['invoice_id' => 'id'])
            ->orderBy(['line_date' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function isOpen()
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isOverdue($today = null)
    {
        return $this->isOpen() && $this->due_on < ($today ?? date('Y-m-d'));
    }

    /** The month billed, e.g. "December 2026". */
    public function getPeriodLabel()
    {
        return date('F Y', strtotime($this->period_start));
    }

    /** Whether it is only a pilot statement of absorbed fees (no plan or cancellation charges). */
    public function isStatement()
    {
        return !$this->pausable;
    }

    public function signature()
    {
        return SelfServiceLink::sign('invoice|' . $this->id . '|' . $this->consultant_id . '|' . $this->number);
    }

    /** Its page, for the consultant: no sign-in needed. */
    public function url($absolute = false)
    {
        $path = '/invoice/' . $this->id . '/' . $this->signature();

        return $absolute ? rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/') . $path : $path;
    }

    /** The account reference on the M-Pesa prompt (12 characters at most), e.g. "INV42". */
    public function mpesaReference()
    {
        return 'INV' . $this->id;
    }

    /** Where its M-Pesa prompt page asks how the payment is going. */
    public function statusUrl()
    {
        return '/invoice/' . $this->id . '/' . $this->signature() . '/status';
    }

    /** The one-tap "Pay via M-Pesa" page (the button in its emails): no sign-in needed. */
    public function payUrl($absolute = false)
    {
        return $this->url($absolute) . '/pay';
    }

    public static function statusLabels()
    {
        return [
            self::STATUS_ISSUED => 'Unpaid',
            self::STATUS_PAID => 'Paid',
            self::STATUS_VOID => 'Void',
        ];
    }
}
