<?php

namespace frontend\services;

use common\components\MpesaException;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\models\MpesaTransaction;
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
 *
 * Part 2: the consultant pays from the invoice page by M-Pesa prompt (Mpesa::EVENT_SETTLED, see
 * onPaymentSettled()); `php yii invoice/remind` reminds them on the due date and a week later, and
 * pauses patients' online booking with them once an invoice carrying standard-terms charges is 14
 * days overdue (a pilot statement gets reminders only). Paying, waiving or voiding lifts the pause.
 */
class Invoices
{
    const LINK_TYPE = 'consultant_invoice';

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

    public static function reminderGapDays()
    {
        return (int) (Yii::$app->params['invoices']['reminderGapDays'] ?? 7);
    }

    public static function pauseAfterDays()
    {
        return (int) (Yii::$app->params['invoices']['pauseAfterDays'] ?? 14);
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
    public static function send(ConsultantInvoice $invoice, $resend = false)
    {
        $consultant = $invoice->consultant;
        if ($consultant === null) {
            return;
        }
        $kind = $invoice->isStatement() ? 'statement of absorbed fees' : 'invoice';
        $text = "Your {$kind} {$invoice->number} for {$invoice->getPeriodLabel()} is Ksh " . number_format($invoice->amount)
            . ', due on ' . date('j M Y', strtotime($invoice->due_on)) . '.';
        self::emailWithPayLink($invoice, ucfirst($kind) . " {$invoice->number}: Ksh " . number_format($invoice->amount), $text);
        self::whatsappWithPayLink($invoice, $text);
        if ($invoice->isOpen() && $invoice->amount > 0) {
            self::remindFrontDesk($invoice, 'was emailed to ' . ($consultant->names ?: 'them') . ' and is due on '
                . date('j M Y', strtotime($invoice->due_on)) . '.');
        }
        if (!$resend) {
            self::sendFacilityCopies($invoice);
        }
    }

    /** Whether the invoice can be paid by M-Pesa prompt now: open, something due, and M-Pesa set up. */
    public static function canPayByPrompt(ConsultantInvoice $invoice)
    {
        return $invoice->isOpen() && $invoice->amount > 0 && Yii::$app->mpesa->isConfigured();
    }

    /**
     * The consultant's email: "Pay via M-Pesa" (the one-tap prompt page) as its button when the
     * invoice can be paid that way, and the invoice's own link for its charges.
     */
    private static function emailWithPayLink(ConsultantInvoice $invoice, $subject, $text)
    {
        if (self::canPayByPrompt($invoice)) {
            self::email($invoice->consultant, $subject, $text . "\n\nTap Pay via M-Pesa below: the M-Pesa prompt comes to your phone and you enter your PIN."
                . "\n\nSee each charge: " . $invoice->url(true), $invoice->payUrl(true), 'Pay via M-Pesa');
        } else {
            self::email($invoice->consultant, $subject, $text . "\n\nOpen it to see each charge.", $invoice->url(true), 'See the invoice');
        }
    }

    private static function whatsappWithPayLink(ConsultantInvoice $invoice, $text)
    {
        self::whatsapp($invoice->consultant, $text . (self::canPayByPrompt($invoice)
            ? ' Pay via M-Pesa: ' . $invoice->payUrl(true)
            : ' ' . $invoice->url(true)));
    }

    /**
     * FYI to the front-desk users assigned to the consultant: remind them to open the invoice
     * email and pay. No charges and no pay link: only the consultant pays.
     */
    private static function remindFrontDesk(ConsultantInvoice $invoice, $what)
    {
        $consultant = $invoice->consultant;
        $users = User::find()
            ->innerJoin('{{%front_desk_assignment}} fda', 'fda.user_id = {{%user}}.id')
            ->where(['fda.consultant_id' => $consultant->id, '{{%user}}.status' => User::STATUS_ACTIVE])
            ->andWhere(['not', ['{{%user}}.email' => null]])->andWhere(['<>', '{{%user}}.email', ''])->all();
        $doctor = $consultant->names ?: 'the doctor';
        foreach ($users as $user) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $user->email,
                'toName' => $user->full_name ?: null,
                'subject' => "Please remind {$doctor}: invoice {$invoice->number}",
                'text' => 'Dear ' . ($user->full_name ?: $user->username) . ",\n\nFor your information: {$doctor}'s invoice {$invoice->number} "
                    . "for {$invoice->getPeriodLabel()}, Ksh " . number_format($invoice->amount) . ", {$what}\n\n"
                    . "Please remind {$doctor} to open the invoice email and pay it via M-Pesa. Only {$doctor} can pay it; nothing is due from you.",
            ]));
        }
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
            'paid_at' => self::now(), 'paid_by' => $userId, 'pay_note' => null], false);
        $invoice->save(false);
        if ($invoice->consultant !== null) {
            self::reviewPause($invoice->consultant);
        }

        return true;
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
            if ($invoice->consultant !== null) {
                self::reviewPause($invoice->consultant);
            }
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
        $invoice->save(false);
        if ($invoice->consultant !== null) {
            self::reviewPause($invoice->consultant);
        }

        return true;
    }

    // ---- Paying by M-Pesa prompt (part 2) --------------------------------------------------

    /**
     * Sends an M-Pesa prompt for the amount due to $phone (the consultant's number by default).
     *
     * @return string|null why it was not sent, or null when the phone is being asked
     */
    public static function sendPrompt(ConsultantInvoice $invoice, $phone = null)
    {
        if (!$invoice->isOpen() || $invoice->amount <= 0) {
            return Yii::t('app', 'Nothing is due on this invoice.');
        }
        $last = $invoice->mpesa_transaction_id ? MpesaTransaction::findOne($invoice->mpesa_transaction_id) : null;
        if ($last !== null && $last->isPending() && $last->created_at > self::now() - 60) {
            return Yii::t('app', 'A prompt was sent less than a minute ago. Check the phone, or wait a minute to send another.');
        }
        $phone = trim((string) ($phone ?: ($invoice->consultant->consultant_phone_number ?? '')));
        try {
            $normalised = \common\components\Mpesa::normalisePhone($phone);
        } catch (\InvalidArgumentException $e) {
            return Yii::t('app', 'Enter a Safaricom number, such as 0712 345 678.');
        }
        try {
            $transaction = Yii::$app->mpesa->stkPush($normalised, $invoice->amount, $invoice->mpesaReference(),
                'Invoice ' . $invoice->number, ['type' => self::LINK_TYPE, 'id' => $invoice->id]);
        } catch (MpesaException $e) {
            Yii::warning("Invoice {$invoice->number}: the M-Pesa prompt was not sent: " . $e->getMessage(), __METHOD__);
            $note = Yii::t('app', 'The M-Pesa prompt could not be sent. Please try again.');
            $invoice->updateAttributes(['pay_note' => $note]);
            return $note;
        }
        $invoice->refresh();
        if (!$invoice->isOpen()) {
            return null; // settled at once, and paid
        }
        $invoice->updateAttributes([
            'mpesa_transaction_id' => $transaction->id,
            'pay_phone' => $normalised,
            'pay_note' => $transaction->isSettled() ? (mb_substr((string) $transaction->result_desc, 0, 255) ?: null) : null,
        ]);

        return $transaction->isSettled() ? ($invoice->pay_note ?: Yii::t('app', 'M-Pesa refused the prompt.')) : null;
    }

    /** Mpesa::EVENT_SETTLED for an invoice's prompt (dispatched by CommitmentFees::onSettled). */
    public static function onPaymentSettled(MpesaTransaction $transaction)
    {
        $invoice = ConsultantInvoice::findOne((int) $transaction->link_id);
        if ($invoice === null) {
            return;
        }
        if ($transaction->status !== MpesaTransaction::STATUS_SUCCEEDED) {
            if ($invoice->isOpen()) {
                $invoice->updateAttributes(['mpesa_transaction_id' => $transaction->id,
                    'pay_note' => mb_substr((string) $transaction->result_desc, 0, 255) ?: Yii::t('app', 'The payment did not go through.')]);
            }
            return;
        }
        if (!$invoice->isOpen()) {
            // Paid twice, or after it was voided: left for a platform admin to refund
            Yii::warning("Invoice {$invoice->number} is {$invoice->status}, but M-Pesa payment {$transaction->receipt} of Ksh {$transaction->amount} came in for it.", __METHOD__);
            return;
        }
        $invoice->mpesa_transaction_id = $transaction->id;
        self::markPaid($invoice, $transaction->receipt ?: ('MPESA-' . $transaction->id));
        $consultant = $invoice->consultant;
        if ($consultant !== null) {
            self::email($consultant, "Paid: {$invoice->number}, Ksh " . number_format($transaction->amount),
                'Thank you: your M-Pesa payment of Ksh ' . number_format($transaction->amount) . " for {$invoice->number} ({$invoice->getPeriodLabel()}) "
                . "was received, receipt {$invoice->reference}.", $invoice->url(true), 'See the invoice');
        }
    }

    /** JSON for the invoice page to follow a prompt. */
    public static function statusOf(ConsultantInvoice $invoice)
    {
        $transaction = $invoice->mpesa_transaction_id ? MpesaTransaction::findOne($invoice->mpesa_transaction_id) : null;

        return [
            'status' => $invoice->status,
            'promptPending' => $transaction !== null && $transaction->isPending(),
            'note' => $invoice->pay_note,
            'reference' => $invoice->reference,
        ];
    }

    // ---- Reminders and the pause (part 2) --------------------------------------------------

    /**
     * The daily run (`php yii invoice/remind`): reminds consultants of invoices due today and a week
     * overdue, pauses online booking for those with a standard-terms invoice long overdue, and lifts
     * pauses no longer owed.
     *
     * @return string[] what was done, one line each
     */
    public static function remindAndPause()
    {
        if (!self::enabled()) {
            return ['Invoices are off (INVOICES_ENABLED=0).'];
        }
        $today = date('Y-m-d', self::now());
        $secondBy = date('Y-m-d', strtotime($today . ' -' . self::reminderGapDays() . ' days'));
        $done = [];
        $due = ConsultantInvoice::find()->with('consultant')
            ->where(['status' => ConsultantInvoice::STATUS_ISSUED])->andWhere(['>', 'amount', 0])
            ->andWhere(['<=', 'due_on', $today])->andWhere(['<', 'reminders_sent', 2])
            ->orderBy(['id' => SORT_ASC])->all();
        foreach ($due as $invoice) {
            $second = $invoice->due_on <= $secondBy;
            if (!$second && $invoice->reminders_sent >= 1) {
                continue;
            }
            self::remind($invoice, $second);
            $invoice->updateAttributes(['reminders_sent' => $second ? 2 : 1, 'reminded_at' => self::now()]);
            $done[] = "{$invoice->number}: " . ($second ? 'second' : 'first') . ' reminder';
        }

        $paused = Consultant::find()->where(['not', ['online_booking_paused_at' => null]])->indexBy('id')->all();
        foreach (self::pausingQuery()->select('consultant_id')->distinct()->column() as $consultantId) {
            if (isset($paused[$consultantId])) {
                unset($paused[$consultantId]);
                continue;
            }
            $consultant = Consultant::findOne($consultantId);
            if ($consultant !== null) {
                self::pause($consultant);
                $done[] = "Paused online booking: {$consultant->names}";
            }
        }
        // Still paused, but nothing long overdue any more
        foreach ($paused as $consultant) {
            if (self::reviewPause($consultant)) {
                $done[] = "Lifted the pause: {$consultant->names}";
            }
        }

        return $done;
    }

    /** Open invoices with standard-terms charges, pauseAfterDays or more past their due date. */
    private static function pausingQuery($consultantId = null)
    {
        $lastDue = date('Y-m-d', strtotime(date('Y-m-d', self::now()) . ' -' . self::pauseAfterDays() . ' days'));

        return ConsultantInvoice::find()
            ->where(['status' => ConsultantInvoice::STATUS_ISSUED, 'pausable' => 1])->andWhere(['>', 'amount', 0])
            ->andWhere(['<=', 'due_on', $lastDue])
            ->andFilterWhere(['consultant_id' => $consultantId]);
    }

    /** The invoices keeping the consultant's online booking paused, oldest first. */
    public static function pausingInvoices(Consultant $consultant)
    {
        return self::pausingQuery($consultant->id)->orderBy(['due_on' => SORT_ASC])->all();
    }

    /** The date an unpaid invoice pauses online booking (Y-m-d), or null for a pilot statement. */
    public static function pausesOn(ConsultantInvoice $invoice)
    {
        return $invoice->pausable ? date('Y-m-d', strtotime($invoice->due_on . ' +' . self::pauseAfterDays() . ' days')) : null;
    }

    private static function remind(ConsultantInvoice $invoice, $second)
    {
        $consultant = $invoice->consultant;
        if ($consultant === null) {
            return;
        }
        $amount = 'Ksh ' . number_format($invoice->amount);
        $due = date('j M Y', strtotime($invoice->due_on));
        $text = $second
            ? "Your invoice {$invoice->number} for {$invoice->getPeriodLabel()}, {$amount}, was due on {$due} and is still unpaid."
            : "Your invoice {$invoice->number} for {$invoice->getPeriodLabel()}, {$amount}, is due today.";
        if (($pausesOn = self::pausesOn($invoice)) !== null) {
            $text .= ' If it is still unpaid on ' . date('j M Y', strtotime($pausesOn)) . ', patients will not be able to book you online'
                . ' until it is paid; your front desk and booking links keep working.';
        }
        self::emailWithPayLink($invoice, ($second ? 'Overdue: ' : 'Due today: ') . "{$invoice->number}, {$amount}", $text);
        self::whatsappWithPayLink($invoice, $text);
        $what = $second ? "was due on {$due} and is still unpaid." : 'is due today.';
        if ($pausesOn !== null) {
            $what .= ' If it is still unpaid on ' . date('j M Y', strtotime($pausesOn)) . ', patients will not be able to book '
                . ($consultant->names ?: 'the doctor') . ' online until it is paid; you can still book for them.';
        }
        self::remindFrontDesk($invoice, $what);
    }

    /** Patients can no longer book the consultant online; the consultant is told what still works. */
    private static function pause(Consultant $consultant)
    {
        $consultant->updateAttributes(['online_booking_paused_at' => self::now()]);
        $invoices = self::pausingInvoices($consultant);
        $first = $invoices[0] ?? null;
        $list = implode(', ', array_map(function (ConsultantInvoice $i) {
            return "{$i->number} (Ksh " . number_format($i->amount) . ')';
        }, $invoices));
        $text = "Patients can no longer book you online, because {$list} " . (count($invoices) > 1 ? 'are' : 'is') . ' more than '
            . self::pauseAfterDays() . ' days overdue. Online booking comes back as soon as it is paid.';
        $more = 'Your profile stays visible; patients are asked to call the clinic to book, and are not told why. '
            . 'Your front desk can still book for them, your booking links still work, and existing appointments are not affected.';
        self::email($consultant, 'Online booking paused: invoice overdue', $text . "\n\n" . $more,
            $first ? (self::canPayByPrompt($first) ? $first->payUrl(true) : $first->url(true)) : null,
            $first ? (self::canPayByPrompt($first) ? 'Pay via M-Pesa' : 'See the invoice') : null);
        self::whatsapp($consultant, $text . ' ' . $more . ($first ? ' ' . $first->url(true) : ''));
    }

    /** Lifts the consultant's pause when nothing long overdue is left, and tells them. */
    public static function reviewPause(Consultant $consultant)
    {
        if (!$consultant->isOnlineBookingPaused() || self::pausingQuery($consultant->id)->exists()) {
            return false;
        }
        $consultant->updateAttributes(['online_booking_paused_at' => null]);
        $text = 'Thank you. Patients can book you online again.';
        self::email($consultant, 'Online booking is back on', $text);
        self::whatsapp($consultant, $text);

        return true;
    }

    private static function whatsapp(Consultant $consultant, $text)
    {
        if ($consultant->consultant_phone_number) {
            Yii::$app->queue->push(new SendWhatsappTextJob([
                'phone' => $consultant->consultant_phone_number,
                'text' => 'Hello ' . ($consultant->names ?: 'Doctor') . ",\n\n" . $text,
            ]));
        }
    }
}

