<?php

namespace frontend\services;

use common\components\MpesaException;
use common\helpers\Phone;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\models\Consent;
use common\models\MpesaTransaction;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\PatientCredit;
use frontend\models\User;
use Yii;

/**
 * F23: what becomes of a commitment fee after booking (docs/business-case.md). Absorbed fees
 * follow the same rules, but are never credited: what would be a credit is simply not billed.
 *
 * - The clinic cancels (any cancellation the patient did not ask for, a facility closure
 *   included): a paid fee becomes a refundable credit; an absorbed one is cancelled.
 * - The patient cancels more than 24 hours ahead: a paid fee becomes a credit (not refundable); an
 *   absorbed one is cancelled.
 * - The patient cancels less than 24 hours ahead, or does not come: the fee is forfeited.
 * - The booking is moved: the fee moves with it. A patient cannot move a paid booking themselves
 *   in its last 24 hours (BookingService::slotProblem()): they cancel, forfeiting the fee, and book
 *   again. Staff moves always carry the fee, and so does a patient's move when the clinic told
 *   them the booking clashes with a closure, time off or holiday (F14).
 *
 * A credit pays the fee of the patient's next charged booking, with any consultant, within 90
 * days. A refundable one can be asked back; a platform admin approves it and it is sent by M-Pesa
 * B2C (MPESA_B2C_PAYOUTS_ENABLED), or paid by hand and recorded with its M-Pesa reference.
 */
class FeeLifecycle
{
    const LINK_TYPE_REFUND = 'credit_refund';
    /** Hours before the appointment within which a patient's cancellation forfeits the fee */
    const NOTICE_HOURS = 24;

    /** @var int|null overrides time() in tests */
    public static $now;

    public static function b2cEnabled()
    {
        return !empty(Yii::$app->params['payouts.b2cEnabled']);
    }

    /** The appointment was cancelled: credit, cancel or forfeit its fee. */
    public static function onCancelled(Appointments $appointment)
    {
        $fee = self::activeFee($appointment);
        if ($fee === null) {
            return;
        }
        $start = strtotime($appointment->date . ' ' . $appointment->time);
        $cancelledAt = (int) ($appointment->cancelled_at ?: self::now());
        $byClinic = !$appointment->cancelled_at_patient_request;
        $early = $start - $cancelledAt > self::NOTICE_HOURS * 3600;

        if (!$byClinic && !$early) {
            self::settle($fee, CommitmentFee::STATE_FORFEITED, 'Cancelled less than 24 hours ahead.');
            return;
        }
        if ($fee->payer === CommitmentFee::PAYER_CONSULTANT) {
            self::settle($fee, CommitmentFee::STATE_CANCELLED, $byClinic ? 'The clinic cancelled: not billed.' : 'Cancelled more than 24 hours ahead: not billed.');
            return;
        }
        self::settle($fee, CommitmentFee::STATE_CREDITED, $byClinic ? 'The clinic cancelled.' : 'Cancelled more than 24 hours ahead.');
        $credit = self::createCredit($fee, $byClinic ? PatientCredit::REASON_CLINIC_CANCELLED : PatientCredit::REASON_PATIENT_CANCELLED, $byClinic);
        self::tellCredited($credit, $appointment);
    }

    /** Attended or no-show was recorded (or corrected): a no-show forfeits the fee. */
    public static function onOutcome(Appointments $appointment)
    {
        $fee = CommitmentFee::forAppointment($appointment->id);
        if ($fee === null) {
            return;
        }
        if ($appointment->status === Appointments::STATUS_NO_SHOW && in_array($fee->state, [CommitmentFee::STATE_PAID, CommitmentFee::STATE_ABSORBED], true)) {
            self::settle($fee, CommitmentFee::STATE_FORFEITED, 'The patient did not come.');
        } elseif ($appointment->status === Appointments::STATUS_COMPLETED && $fee->state === CommitmentFee::STATE_FORFEITED && $fee->note === 'The patient did not come.') {
            // A no-show corrected to attended
            $fee->setAttributes([
                'state' => $fee->payer === CommitmentFee::PAYER_CONSULTANT ? CommitmentFee::STATE_ABSORBED : CommitmentFee::STATE_PAID,
                'settled_at' => null,
                'note' => null,
            ], false);
            $fee->save(false);
        }
    }

    /** The appointment was moved: its fee moves with it. */
    public static function onMoved(Appointments $appointment, $oldDate, $oldTime)
    {
        $fee = self::activeFee($appointment);
        if ($fee === null) {
            return;
        }
        $fee->setAttributes([
            'fee_date' => $appointment->date,
            'note' => mb_substr('Moved from ' . $oldDate . ' ' . substr((string) $oldTime, 0, 5) . '.', 0, 255),
        ], false);
        $fee->save(false);
    }

    /**
     * Why the patient may not move this appointment themselves now: a paid booking in its last 24
     * hours. Null when they may.
     */
    public static function patientMoveProblem(Appointments $appointment)
    {
        $oldStart = strtotime($appointment->getOldAttribute('date') . ' ' . $appointment->getOldAttribute('time'));
        if ($oldStart - self::now() > self::NOTICE_HOURS * 3600 || self::activeFee($appointment) === null) {
            return null;
        }
        // F14: told it cannot go ahead (a closure, time off or holiday): the clinic asked them to
        // move, so they may, and the fee moves with it as when staff move it
        if (Clashes::mustMove($appointment)) {
            return null;
        }

        return Yii::t('app', 'Less than 24 hours before the appointment it cannot be moved: the Ksh {amount} commitment fee is kept if you cancel, and a new booking pays again.', ['amount' => FeeRules::amount()]);
    }

    /** The patient's oldest usable credit, or null. */
    public static function usableCredit($patientId)
    {
        return PatientCredit::find()
            ->where(['patient_id' => $patientId, 'status' => [PatientCredit::STATUS_AVAILABLE, PatientCredit::STATUS_REFUND_REJECTED]])
            ->andWhere(['>', 'expires_at', self::now()])
            ->orderBy(['expires_at' => SORT_ASC, 'id' => SORT_ASC])
            ->one();
    }

    /** The credit paid this new fee. */
    public static function useCredit(PatientCredit $credit, CommitmentFee $fee)
    {
        $credit->setAttributes(['status' => PatientCredit::STATUS_USED, 'used_fee_id' => $fee->id, 'used_at' => self::now()], false);
        $credit->save(false);
    }

    /** A payment that arrived after its time was taken: kept as a refundable credit. */
    public static function creditLatePayment(CommitmentFee $fee)
    {
        self::settle($fee, CommitmentFee::STATE_CREDITED, 'Paid after the time was released and taken.');
        $credit = self::createCredit($fee, PatientCredit::REASON_LATE_PAYMENT, true);
        self::tellCredited($credit, $fee->appointment);

        return $credit;
    }

    /**
     * The patient asks for a refundable credit back by M-Pesa, to $phone (theirs by default). A
     * platform admin approves it.
     */
    public static function requestRefund(PatientCredit $credit, $phone = null)
    {
        if (!$credit->canRequestRefund(self::now())) {
            return false;
        }
        $phone = Phone::normalize($phone ?: (User::find()->select('phone_number')->where(['id' => $credit->patient_id])->scalar() ?: ''));
        if ($phone === null || strpos($phone, Phone::DEFAULT_COUNTRY) !== 0) {
            $credit->addError('refund_phone', Yii::t('app', 'Enter the Kenyan M-Pesa number to refund to.'));
            return false;
        }
        $credit->setAttributes(['status' => PatientCredit::STATUS_REFUND_REQUESTED, 'refund_requested_at' => self::now(), 'refund_phone' => $phone], false);

        return $credit->save(false);
    }

    /**
     * A platform admin approves a requested refund. With B2C on it is sent now and settles when
     * M-Pesa answers; otherwise pay it by hand and record it with markRefunded().
     *
     * @return bool whether it was approved (and, with B2C, sent)
     */
    public static function approveRefund(PatientCredit $credit, $adminId)
    {
        if ($credit->status !== PatientCredit::STATUS_REFUND_REQUESTED) {
            return false;
        }
        $credit->setAttributes(['refund_decided_by' => $adminId, 'refund_decided_at' => self::now()], false);
        if (!self::b2cEnabled()) {
            $credit->note = 'Approved: pay it by M-Pesa by hand and record the reference.';
            return $credit->save(false);
        }
        try {
            $transaction = Yii::$app->mpesa->b2cPayment($credit->refund_phone, $credit->amount, 'Commitment fee refund',
                'Refund', ['type' => self::LINK_TYPE_REFUND, 'id' => $credit->id]);
        } catch (MpesaException | \InvalidArgumentException $e) {
            $credit->note = mb_substr('B2C failed: ' . $e->getMessage(), 0, 255);
            $credit->save(false);
            return false;
        }
        $credit->setAttributes(['refund_mpesa_transaction_id' => $transaction->id, 'note' => 'Sent by M-Pesa B2C.'], false);
        $credit->save(false);
        if ($transaction->isSettled()) {
            self::onRefundSettled($transaction);
        }

        return true;
    }

    /** A refund paid by hand: recorded with its M-Pesa reference. */
    public static function markRefunded(PatientCredit $credit, $reference, $adminId)
    {
        $reference = strtoupper(trim((string) $reference));
        if ($credit->status !== PatientCredit::STATUS_REFUND_REQUESTED || !preg_match('/^[A-Z0-9]{6,20}$/', $reference)) {
            return false;
        }
        $credit->setAttributes([
            'status' => PatientCredit::STATUS_REFUNDED,
            'refund_reference' => $reference,
            'refunded_at' => self::now(),
            'refund_decided_by' => $credit->refund_decided_by ?: $adminId,
            'refund_decided_at' => $credit->refund_decided_at ?: self::now(),
        ], false);
        $credit->save(false);
        self::refundFee($credit);

        return true;
    }

    /** A platform admin declines a refund: the credit stays usable until it expires. */
    public static function rejectRefund(PatientCredit $credit, $adminId, $reason)
    {
        if ($credit->status !== PatientCredit::STATUS_REFUND_REQUESTED) {
            return false;
        }
        $credit->setAttributes([
            'status' => PatientCredit::STATUS_REFUND_REJECTED,
            'refund_decided_by' => $adminId,
            'refund_decided_at' => self::now(),
            'note' => mb_substr('Refund declined: ' . trim((string) $reason), 0, 255),
        ], false);

        return $credit->save(false);
    }

    /** Mpesa::EVENT_SETTLED for a refund's B2C payment. */
    public static function onRefundSettled(MpesaTransaction $transaction)
    {
        $credit = PatientCredit::findOne((int) $transaction->link_id);
        if ($credit === null || $credit->status !== PatientCredit::STATUS_REFUND_REQUESTED) {
            return;
        }
        if ($transaction->status === MpesaTransaction::STATUS_SUCCEEDED) {
            $credit->setAttributes([
                'status' => PatientCredit::STATUS_REFUNDED,
                'refund_reference' => $transaction->receipt,
                'refunded_at' => self::now(),
                'note' => null,
            ], false);
            $credit->save(false);
            self::refundFee($credit);
        } else {
            // Still requested: the admin can approve again or pay by hand
            $credit->note = mb_substr('B2C did not go through: ' . $transaction->result_desc, 0, 255);
            $credit->save(false);
        }
    }

    /** Credits past their 90 days. Run with fee/release. */
    public static function expireCredits()
    {
        return PatientCredit::updateAll(
            ['status' => PatientCredit::STATUS_EXPIRED, 'updated_at' => self::now()],
            ['and', ['status' => [PatientCredit::STATUS_AVAILABLE, PatientCredit::STATUS_REFUND_REJECTED]], ['<=', 'expires_at', self::now()]]
        );
    }

    private static function refundFee(PatientCredit $credit)
    {
        $fee = $credit->sourceFee;
        if ($fee !== null && $fee->state === CommitmentFee::STATE_CREDITED) {
            $fee->setAttributes(['state' => CommitmentFee::STATE_REFUNDED, 'settled_at' => self::now()], false);
            $fee->save(false);
        }
    }

    private static function activeFee(Appointments $appointment)
    {
        $fee = CommitmentFee::forAppointment($appointment->id);

        return $fee !== null && in_array($fee->state, [CommitmentFee::STATE_PAID, CommitmentFee::STATE_ABSORBED], true) ? $fee : null;
    }

    private static function settle(CommitmentFee $fee, $state, $note)
    {
        $fee->setAttributes(['state' => $state, 'settled_at' => self::now(), 'note' => $note], false);
        $fee->save(false);
    }

    private static function createCredit(CommitmentFee $fee, $reason, $refundable)
    {
        $credit = new PatientCredit([
            'patient_id' => $fee->patient_id,
            'amount' => $fee->amount,
            'source_fee_id' => $fee->id,
            'reason' => $reason,
            'refundable' => $refundable ? 1 : 0,
            'status' => PatientCredit::STATUS_AVAILABLE,
            'expires_at' => self::now() + PatientCredit::VALID_DAYS * 86400,
        ]);
        $credit->save(false);

        return $credit;
    }

    /** One email, and WhatsApp with consent: the fee is kept as credit (and how to get it back). */
    private static function tellCredited(PatientCredit $credit, ?Appointments $appointment)
    {
        $patient = User::findOne($credit->patient_id);
        if ($patient === null) {
            return;
        }
        $until = date('j M Y', (int) $credit->expires_at);
        $text = 'Your Ksh ' . number_format($credit->amount) . ' commitment fee'
            . ($appointment ? ' for ' . date('D j M', strtotime($appointment->date)) . ' at ' . substr((string) $appointment->time, 0, 5) : '')
            . " is kept as a credit: your next booking, with any doctor, uses it instead of a new payment, until $until.";
        if ($credit->refundable) {
            $text .= ' To get it back by M-Pesa instead: ' . $credit->url(true);
        }

        if ($patient->email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $patient->email,
                'toName' => $patient->full_name ?: null,
                'subject' => 'Your commitment fee is kept as a credit',
                'text' => 'Dear ' . ($patient->full_name ?: $patient->username) . ",\n\n" . $text,
                'linkUrl' => $credit->url(true),
                'linkLabel' => $credit->refundable ? 'See the credit, or get a refund' : 'See the credit',
            ]));
        }
        if ($patient->phone_number && Consent::remindersAllowed($patient->id)) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $patient->phone_number, 'text' => $text]));
        }
    }

    private static function now()
    {
        return self::$now ?? time();
    }
}
