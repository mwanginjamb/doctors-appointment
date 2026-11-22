<?php

namespace frontend\services;

use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use Yii;

/**
 * F25: consultants' monthly invoices (docs/business-case.md; agreement v1.2 §3–§5). One invoice a
 * month per consultant, across all their facilities, issued on the 1st for the month before
 * (`php yii invoice/issue`) and due 14 days later. Its lines:
 *
 * - Absorbed fees: each commitment fee the consultant absorbed for an insured patient (F22) and
 *   still owes: absorbed, or forfeited by a no-show or late cancellation; not one cancelled (F23).
 *   During the pilot the invoice is only this: a statement.
 * - The plan charge: Plan A or B's monthly price, for an active consultant (an appointment that
 *   month) on standard terms (past their day 90, at a facility whose terms were approved),
 *   prorated by the days of the month on standard terms.
 * - Cancellation charges: Ksh 100 for each booking the consultant or their front desk cancelled,
 *   not at the patient's request nor for a closure, once on standard terms at a facility that
 *   signed v1.2. Added when the booking is cancelled, and the consultant is emailed.
 *
 * The consultant is emailed and WhatsApped the invoice; each facility admin gets the lines from
 * their facility. Off until INVOICES_ENABLED=1.
 */
class Invoices
{
    /** @var int|null overrides time() in tests */
    public static $now;

    public static function enabled()
    {
        return !empty(Yii::$app->params['invoices']['enabled']);
    }

    public static function planMonthly($plan)
    {
        return (int) (Yii::$app->params['invoices']['planMonthly'][$plan] ?? 0);
    }

    public static function cancellationCharge()
    {
        return (int) (Yii::$app->params['invoices']['cancellationCharge'] ?? 100);
    }

    public static function dueDays()
    {
        return (int) (Yii::$app->params['invoices']['dueDays'] ?? 14);
    }

    private static function now()
    {
        return self::$now ?? time();
    }

    // ---- Cancellation charges --------------------------------------------------------------

    /**
     * A booking was cancelled (Appointments::cancel()): charges the consultant Ksh 100 when they or
     * their front desk cancelled it, not at the patient's request nor for a facility closure, on
     * standard terms at a facility whose agreement includes the charge. The consultant is emailed.
     *
     * @return ConsultantInvoiceLine|null the charge, or null when none applies
     */
    public static function onCancelled(Appointments $appointment)
    {
        if (!self::enabled()
            || !in_array($appointment->cancelled_party, [Appointments::PARTY_CONSULTANT, Appointments::PARTY_FRONT_DESK], true)
            || $appointment->cancelled_at_patient_request
            || !$appointment->facility_id) {
            return null;
        }
        $consultant = $appointment->consultant;
        $agreement = FacilityAgreement::currentFor($appointment->facility_id);
        if ($consultant === null || $agreement === null || !$agreement->hasCancellationCharge()
            || !Pilot::onStandardTerms($consultant, $appointment->facility_id, $appointment->cancelled_at ?: null)) {
            return null;
        }
        if (ConsultantInvoiceLine::find()->where(['type' => ConsultantInvoiceLine::TYPE_CANCELLATION, 'source_id' => $appointment->id])->exists()) {
            return null;
        }

        $byFrontDesk = $appointment->cancelled_party === Appointments::PARTY_FRONT_DESK;
        $who = $byFrontDesk ? User::findOne($appointment->cancelled_by) : null;
        $when = date('j M Y', strtotime($appointment->date)) . ' ' . substr((string) $appointment->time, 0, 5);
        $facility = $appointment->facility;
        $line = new ConsultantInvoiceLine([
            'consultant_id' => $consultant->id,
            'type' => ConsultantInvoiceLine::TYPE_CANCELLATION,
            'source_id' => $appointment->id,
            'facility_id' => $appointment->facility_id,
            'line_date' => date('Y-m-d', $appointment->cancelled_at ?: self::now()),
            'description' => "Booking #{$appointment->id} on {$when}" . ($facility ? " at {$facility->name}" : '')
                . ' cancelled by ' . ($byFrontDesk ? 'the front desk' . ($who ? ' (' . ($who->full_name ?: $who->username) . ')' : '') : 'you'),
            'amount' => self::cancellationCharge(),
        ]);
        $line->save(false);

        self::email($consultant, "Ksh {$line->amount} cancellation charge: booking on {$when}",
            'The booking #' . $appointment->id . " on {$when}" . ($facility ? " at {$facility->name}" : '') . ' was cancelled by '
            . ($byFrontDesk ? 'the front desk' : 'you') . ", not at the patient's request. Under the standard terms, Ksh {$line->amount} "
            . "is added to your monthly invoice for it.\n\nNo charge applies when the patient asked for the cancellation, or when the facility is closed for the day: "
            . 'when that was the case, choose that reason when cancelling, or reply to this email.');

        return $line;
    }

    // ---- The monthly run -------------------------------------------------------------------

    /**
     * Issues the invoices for the month starting $monthStart (Y-m-d; last month by default), to each
     * consultant with something to bill who has none for it yet, and sends them.
     *
     * @return string[] what was done, one line each
     */
    public static function issueMonth($monthStart = null)
    {
        if (!self::enabled()) {
            return ['Invoices are off (INVOICES_ENABLED=0).'];
        }
        $start = date('Y-m-01', $monthStart ? strtotime($monthStart) : strtotime('first day of last month', self::now()));
        $end = date('Y-m-t', strtotime($start));
        $done = [];
        foreach (Consultant::find()->orderBy(['id' => SORT_ASC])->all() as $consultant) {
            $invoice = self::issueFor($consultant, $start, $end);
            if ($invoice !== null) {
                self::send($invoice);
                $done[] = "{$invoice->number}: {$consultant->names}, Ksh " . number_format($invoice->amount);
            }
        }

        return $done;
    }

    /**
     * The consultant's invoice for the period: its open lines (absorbed fees and cancellation
     * charges up to the end of it, the plan charge for it). Null when there is nothing to bill or
     * it was issued already.
     */
    public static function issueFor(Consultant $consultant, $start, $end)
    {
        $existing = ConsultantInvoice::find()->where(['consultant_id' => $consultant->id, 'period_start' => $start]);
        if ((clone $existing)->andWhere(['not', ['status' => ConsultantInvoice::STATUS_VOID]])->exists()) {
            return null;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            self::addAbsorbedLines($consultant, $end);
            self::addPlanLine($consultant, $start, $end);
            $lines = ConsultantInvoiceLine::find()
                ->where(['consultant_id' => $consultant->id, 'invoice_id' => null, 'waived' => 0])
                ->andWhere(['<=', 'line_date', $end])->all();
            if (!$lines) {
                $transaction->commit();
                return null;
            }

            $voided = (int) $existing->count();
            $issuedAt = self::now();
            $invoice = new ConsultantInvoice([
                'number' => 'INV-' . date('Ym', strtotime($start)) . '-C' . str_pad((string) $consultant->id, 4, '0', STR_PAD_LEFT) . ($voided ? '-' . ($voided + 1) : ''),
                'consultant_id' => $consultant->id,
                'period_start' => $start,
                'period_end' => $end,
                'amount' => 0,
                'status' => ConsultantInvoice::STATUS_ISSUED,
                'issued_at' => $issuedAt,
                'due_on' => date('Y-m-d', strtotime('+' . self::dueDays() . ' days', $issuedAt)),
            ]);
            $invoice->save(false);
            ConsultantInvoiceLine::updateAll(['invoice_id' => $invoice->id], ['id' => array_column($lines, 'id')]);
            self::recalculate($invoice);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $invoice;
    }

    /** Absorbed fees still owed, up to $end, not billed yet. */
    private static function addAbsorbedLines(Consultant $consultant, $end)
    {
        if (!$consultant->user_id) {
            return;
        }
        $billed = ConsultantInvoiceLine::find()->select('source_id')->where(['type' => ConsultantInvoiceLine::TYPE_ABSORBED]);
        $fees = CommitmentFee::find()->with(['appointment', 'facility'])
            ->where(['consultant_id' => $consultant->user_id, 'payer' => CommitmentFee::PAYER_CONSULTANT,
                'state' => [CommitmentFee::STATE_ABSORBED, CommitmentFee::STATE_FORFEITED]])
            ->andWhere(['<=', 'fee_date', $end])
            ->andWhere(['not in', 'id', $billed])
            ->orderBy(['fee_date' => SORT_ASC, 'id' => SORT_ASC])->all();
        foreach ($fees as $fee) {
            $time = $fee->appointment ? ' ' . substr((string) $fee->appointment->time, 0, 5) : '';
            (new ConsultantInvoiceLine([
                'consultant_id' => $consultant->id,
                'type' => ConsultantInvoiceLine::TYPE_ABSORBED,
                'source_id' => $fee->id,
                'facility_id' => $fee->facility_id,
                'line_date' => $fee->fee_date,
                'description' => "Commitment fee absorbed for an insured patient: booking #{$fee->appointment_id} on "
                    . date('j M Y', strtotime($fee->fee_date)) . $time . ($fee->facility ? " at {$fee->facility->name}" : '')
                    . ($fee->state === CommitmentFee::STATE_FORFEITED ? ' (the patient did not come or cancelled late)' : ''),
                'amount' => (int) $fee->amount,
            ]))->save(false);
        }
    }

    /** The plan charge for the month, prorated by its days on standard terms. */
    private static function addPlanLine(Consultant $consultant, $start, $end)
    {
        $monthly = self::planMonthly($consultant->plan);
        if ($monthly <= 0 || !self::isActive($consultant, $start, $end)) {
            return;
        }
        // Charged once per month, even when a void invoice is issued again
        if (ConsultantInvoiceLine::find()->where(['consultant_id' => $consultant->id, 'type' => ConsultantInvoiceLine::TYPE_PLAN, 'line_date' => $end])->exists()) {
            return;
        }
        $days = self::standardTermsDays($consultant, $start, $end);
        if ($days === 0) {
            return;
        }
        $inMonth = (int) date('t', strtotime($start));
        $amount = (int) round($monthly * $days / $inMonth);
        $plan = FeeRules::planLabels()[$consultant->plan] ?? "Plan {$consultant->plan}";
        (new ConsultantInvoiceLine([
            'consultant_id' => $consultant->id,
            'type' => ConsultantInvoiceLine::TYPE_PLAN,
            'line_date' => $end,
            'description' => "{$plan}, Ksh " . number_format($monthly) . ' a month, ' . date('F Y', strtotime($start))
                . ($days < $inMonth
                    ? ": prorated for {$days} of {$inMonth} days on standard terms (Ksh " . number_format($monthly) . " × {$days}/{$inMonth})"
                    : ''),
            'amount' => $amount,
        ]))->save(false);
    }

    /** Active: at least one appointment, of any status, in the period. */
    public static function isActive(Consultant $consultant, $start, $end)
    {
        return $consultant->user_id && Appointments::find()
            ->where(['consultant_id' => $consultant->user_id])
            ->andWhere(['between', 'date', $start, $end])->exists();
    }

    /**
     * The days from $start to $end on which the consultant was on standard terms: past their own day
     * 90, and engaged at a facility whose standard terms were approved by the end of that day and
     * which had not left.
     */
    public static function standardTermsDays(Consultant $consultant, $start, $end)
    {
        $approvals = [];
        foreach (ConsultantFacility::find()->select('facility_id')->where(['consultant_id' => $consultant->id])->column() as $facilityId) {
            $agreement = FacilityAgreement::currentFor($facilityId);
            if ($agreement !== null && $agreement->standard_terms_approved_at) {
                $approvals[] = [(int) $agreement->standard_terms_approved_at, $agreement->ended_on];
            }
        }
        if (!$approvals) {
            return 0;
        }
        $days = 0;
        for ($day = $start; $day <= $end; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $endOfDay = strtotime($day . ' 23:59:59');
            if (Pilot::phase($consultant, $endOfDay) !== Pilot::PHASE_STANDARD) {
                continue;
            }
            foreach ($approvals as [$approvedAt, $endedOn]) {
                if ($approvedAt <= $endOfDay && ($endedOn === null || $day <= $endedOn)) {
                    $days++;
                    break;
                }
            }
        }

        return $days;
    }

    /** Sets the total from the lines not waived, and whether it carries standard-terms charges. */
    public static function recalculate(ConsultantInvoice $invoice)
    {
        $lines = ConsultantInvoiceLine::find()->where(['invoice_id' => $invoice->id, 'waived' => 0])->all();
        $invoice->amount = array_sum(array_column($lines, 'amount'));
        $invoice->pausable = 0;
        foreach ($lines as $line) {
            if ($line->type !== ConsultantInvoiceLine::TYPE_ABSORBED) {
                $invoice->pausable = 1;
            }
        }
        $invoice->save(false, ['amount', 'pausable', 'updated_at']);
    }

    // ---- Sending ---------------------------------------------------------------------------

    /**
     * Sends the invoice to the consultant (email with its link, and WhatsApp), and to each facility
     * admin the lines from their facility.
     */
    public static function send(ConsultantInvoice $invoice)
    {
        $consultant = $invoice->consultant;
        if ($consultant === null) {
            return;
        }
        $kind = $invoice->isStatement() ? 'statement of absorbed fees' : 'invoice';
        $text = "Your {$kind} {$invoice->number} for {$invoice->getPeriodLabel()} is Ksh " . number_format($invoice->amount)
            . ', due on ' . date('j M Y', strtotime($invoice->due_on)) . '.';
        self::email($consultant, ucfirst($kind) . " {$invoice->number}: Ksh " . number_format($invoice->amount),
            $text . "\n\nOpen it to see each charge.", $invoice->url(true), 'See the invoice');
        if ($consultant->consultant_phone_number) {
            Yii::$app->queue->push(new SendWhatsappTextJob([
                'phone' => $consultant->consultant_phone_number,
                'text' => 'Hello ' . ($consultant->names ?: 'Doctor') . ",\n\n{$text} " . $invoice->url(true),
            ]));
        }
        self::sendFacilityCopies($invoice);
    }

    /** Each facility admin gets, read only, the lines from their facility (not the plan charge). */
    private static function sendFacilityCopies(ConsultantInvoice $invoice)
    {
        $byFacility = [];
        foreach ($invoice->getLines()->andWhere(['waived' => 0])->andWhere(['not', ['facility_id' => null]])->all() as $line) {
            $byFacility[$line->facility_id][] = $line;
        }
        foreach ($byFacility as $facilityId => $lines) {
            $facility = Facility::findOne($facilityId);
            $admins = User::find()->where(['role' => User::ACCOUNT_FACILITY_ADMIN, 'facility_id' => $facilityId, 'status' => User::STATUS_ACTIVE])->all();
            if ($facility === null || !$admins) {
                continue;
            }
            $total = array_sum(array_column($lines, 'amount'));
            $list = implode("\n", array_map(function (ConsultantInvoiceLine $line) {
                return '- ' . $line->description . ': Ksh ' . number_format($line->amount);
            }, $lines));
            foreach ($admins as $admin) {
                Yii::$app->queue->push(new SendEmailMessageJob([
                    'to' => $admin->email,
                    'toName' => $admin->full_name ?: null,
                    'subject' => "{$invoice->consultant->names}'s {$invoice->getPeriodLabel()} invoice: Ksh " . number_format($total) . " from {$facility->name}",
                    'text' => 'Dear ' . ($admin->full_name ?: $admin->username) . ",\n\nFor your information: {$invoice->consultant->names}'s invoice "
                        . "{$invoice->number} for {$invoice->getPeriodLabel()} has these charges from {$facility->name}:\n\n{$list}\n\n"
                        . 'The consultant pays the invoice; nothing is due from the facility.',
                ]));
            }
        }
    }

    private static function email(Consultant $consultant, $subject, $body, $linkUrl = null, $linkLabel = null)
    {
        $to = $consultant->consultant_email ?: ($consultant->user->email ?? null);
        if (!$to) {
            return;
        }
        Yii::$app->queue->push(new SendEmailMessageJob(array_filter([
            'to' => $to,
            'toName' => $consultant->names ?: null,
            'subject' => $subject,
            'text' => 'Dear ' . ($consultant->names ?: 'Doctor') . ",\n\n" . $body,
            'linkUrl' => $linkUrl,
            'linkLabel' => $linkLabel,
        ])));
    }

    // ---- Platform admins -------------------------------------------------------------------

    /** Records a payment made by M-Pesa or other means, with its reference. */
    public static function markPaid(ConsultantInvoice $invoice, $reference, $userId = null)
    {
        $reference = strtoupper(preg_replace('/\s+/', '', (string) $reference));
        if (!$invoice->isOpen() || !preg_match('/^[A-Z0-9-]{4,40}$/', $reference)) {
            return false;
        }
        $invoice->setAttributes(['status' => ConsultantInvoice::STATUS_PAID, 'reference' => $reference,
            'paid_at' => self::now(), 'paid_by' => $userId], false);

        return $invoice->save(false);
    }

    /** Takes a line off an unpaid invoice, with the reason. */
    public static function waive(ConsultantInvoiceLine $line, $reason)
    {
        $reason = trim((string) $reason);
        $invoice = $line->invoice;
        if ($reason === '' || $line->waived || ($invoice !== null && !$invoice->isOpen())) {
            return false;
        }
        $line->setAttributes(['waived' => 1, 'waive_reason' => mb_substr($reason, 0, 255)], false);
        $line->save(false);
        if ($invoice !== null) {
            self::recalculate($invoice);
        }

        return true;
    }

    /** Cancels an unpaid invoice made in error; its lines are waived with the same reason. */
    public static function void(ConsultantInvoice $invoice, $reason, $userId = null)
    {
        $reason = trim((string) $reason);
        if ($reason === '' || !$invoice->isOpen()) {
            return false;
        }
        ConsultantInvoiceLine::updateAll(['waived' => 1, 'waive_reason' => mb_substr('Invoice void: ' . $reason, 0, 255)], ['invoice_id' => $invoice->id, 'waived' => 0]);
        $invoice->setAttributes(['status' => ConsultantInvoice::STATUS_VOID, 'void_reason' => mb_substr($reason, 0, 255),
            'voided_at' => self::now(), 'voided_by' => $userId], false);

        return $invoice->save(false);
    }
}
