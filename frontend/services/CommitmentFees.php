<?php

namespace frontend\services;

use common\components\MpesaEvent;
use common\components\MpesaException;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\models\Consent;
use common\models\MpesaTransaction;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\User;
use Yii;
use yii\db\Connection;

/**
 * F22: paying the Ksh 250 commitment fee at booking (docs/business-case.md).
 *
 * A booking FeeRules charges is saved awaiting payment, holding its slot for holdMinutes(), and an
 * M-Pesa prompt goes to the paying phone once the booking is committed. When M-Pesa reports the
 * payment (Mpesa::EVENT_SETTLED, see onSettled()), the fee is paid and the booking scheduled, which
 * sends the usual confirmation and reminders. A hold that runs out unpaid is released by
 * releaseExpired() (php yii fee/release, every minute): the booking becomes unpaid, the slot is
 * free, and the patient is told, with a link to book again.
 *
 * A fee a consultant absorbs for an insured patient is recorded at once; there is nothing to pay.
 */
class CommitmentFees
{
    /** The M-Pesa transaction link type of a commitment fee */
    const LINK_TYPE = 'commitment_fee';

    /** @var int|null overrides time() in tests */
    public static $now;

    public static function holdMinutes()
    {
        return max(1, (int) (Yii::$app->params['commitmentFee']['holdMinutes'] ?? 10));
    }

    /**
     * Records the fee of a new booking, as Appointments::validateFee() decided: absorbed, or pending
     * with the M-Pesa prompt sent once the booking is committed.
     */
    public static function recordFor(Appointments $appointment)
    {
        $decision = $appointment->feeDecision[0] ?? FeeRules::NONE;
        if ($decision === FeeRules::NONE) {
            return null;
        }
        if ($decision === FeeRules::CREDIT) {
            return self::payWithCredit($appointment, (int) ($appointment->feeDecision[2] ?? 0));
        }
        $absorbed = $decision === FeeRules::ABSORB;
        $fee = new CommitmentFee([
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'consultant_id' => $appointment->consultant_id,
            'facility_id' => $appointment->facility_id,
            'fee_date' => $appointment->date,
            'amount' => FeeRules::amount(),
            'payer' => $absorbed ? CommitmentFee::PAYER_CONSULTANT : CommitmentFee::PAYER_PATIENT,
            'state' => $absorbed ? CommitmentFee::STATE_ABSORBED : CommitmentFee::STATE_PENDING,
            'phone' => $absorbed ? null : $appointment->payPhone,
            'terms_accepted_at' => $absorbed ? null : self::now(),
        ]);
        $fee->save(false);

        if ($absorbed) {
            // F24: an absorbed fee confirms the booking at once
            Incentives::onFeeConfirmed($fee);
        } else {
            self::afterCommit(function () use ($fee) {
                self::sendPrompt($fee);
            });
        }

        return $fee;
    }

    /** F23: the fee of a new booking, paid at once with one of the patient's credits. */
    private static function payWithCredit(Appointments $appointment, $creditId)
    {
        $credit = \frontend\models\PatientCredit::findOne($creditId);
        $fee = new CommitmentFee([
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'consultant_id' => $appointment->consultant_id,
            'facility_id' => $appointment->facility_id,
            'fee_date' => $appointment->date,
            'amount' => FeeRules::amount(),
            'payer' => CommitmentFee::PAYER_PATIENT,
            'state' => CommitmentFee::STATE_PAID,
            'paid_at' => self::now(),
            'terms_accepted_at' => self::now(),
            'note' => 'Paid with credit #' . $creditId . '.',
        ]);
        $fee->save(false);
        if ($credit !== null) {
            FeeLifecycle::useCredit($credit, $fee);
        }
        Incentives::onFeeConfirmed($fee);

        return $fee;
    }

    /**
     * Sends the M-Pesa prompt for a pending fee. False when it could not be sent (the reason is in
     * the fee's note); the patient or staff can send it again while the hold lasts.
     */
    public static function sendPrompt(CommitmentFee $fee)
    {
        try {
            $transaction = Yii::$app->mpesa->stkPush($fee->phone, $fee->amount, 'HealthFirst',
                'Booking fee', ['type' => self::LINK_TYPE, 'id' => $fee->id]);
        } catch (MpesaException | \InvalidArgumentException $e) {
            Yii::warning("Commitment fee {$fee->id}: the M-Pesa prompt was not sent: " . $e->getMessage(), __METHOD__);
            $fee->updateAttributes(['note' => mb_substr('The M-Pesa prompt could not be sent. Please try again.', 0, 255)]);
            return false;
        }
        $fee->updateAttributes([
            'mpesa_transaction_id' => $transaction->id,
            'note' => $transaction->isSettled() ? mb_substr((string) $transaction->result_desc, 0, 255) : null,
        ]);

        return !$transaction->isSettled();
    }

    /**
     * Sends the prompt again, while the booking still holds its slot. False when it may not be sent
     * now (not awaiting payment, the hold ran out, or the last prompt is under a minute old).
     */
    public static function resend(Appointments $appointment)
    {
        $fee = CommitmentFee::forAppointment($appointment->id);
        if (!self::isHeld($appointment) || $fee === null || $fee->state !== CommitmentFee::STATE_PENDING) {
            return false;
        }
        $last = $fee->mpesa_transaction_id ? MpesaTransaction::findOne($fee->mpesa_transaction_id) : null;
        if ($last !== null && $last->isPending() && $last->created_at > self::now() - 60) {
            return false;
        }

        return self::sendPrompt($fee);
    }

    /**
     * Mpesa::EVENT_SETTLED: an M-Pesa payment the app made or asked for ended. Commitment fees are
     * handled here; refunds of credits by FeeLifecycle, front-desk payouts by Incentives.
     */
    public static function onSettled(MpesaEvent $event)
    {
        $transaction = $event->transaction;
        if (!$transaction->link_id) {
            return;
        }
        if ($transaction->link_type === FeeLifecycle::LINK_TYPE_REFUND) {
            FeeLifecycle::onRefundSettled($transaction);
            return;
        }
        if ($transaction->link_type === Incentives::LINK_TYPE) {
            Incentives::onPayoutSettled($transaction);
            return;
        }
        if ($transaction->link_type === Invoices::LINK_TYPE) {
            Invoices::onPaymentSettled($transaction);
            return;
        }
        if ($transaction->link_type !== self::LINK_TYPE) {
            return;
        }
        $fee = CommitmentFee::findOne((int) $transaction->link_id);
        if ($fee === null || $fee->state !== CommitmentFee::STATE_PENDING) {
            return;
        }
        if ($transaction->status === MpesaTransaction::STATUS_SUCCEEDED) {
            self::markPaid($fee, $transaction);
        } else {
            // Cancelled on the phone, failed or timed out: still payable while the hold lasts
            $fee->updateAttributes(['mpesa_transaction_id' => $transaction->id, 'note' => mb_substr((string) $transaction->result_desc, 0, 255)]);
        }
    }

    /**
     * The fee is paid: the held booking is confirmed. A payment that arrives after the hold was
     * released books the time again if it is still free; otherwise the fee stays paid, noted for a
     * refund.
     */
    public static function markPaid(CommitmentFee $fee, MpesaTransaction $transaction)
    {
        $fee->setAttributes([
            'state' => CommitmentFee::STATE_PAID,
            'paid_at' => self::now(),
            'mpesa_transaction_id' => $transaction->id,
            'note' => null,
        ], false);
        $fee->save(false);

        $appointment = $fee->appointment;
        if ($appointment === null) {
            return;
        }
        if ($appointment->status === Appointments::STATUS_UNPAID) {
            $service = Yii::createObject(BookingService::class);
            $start = strtotime($appointment->date . ' ' . $appointment->time);
            if ($service->overlapProblem($appointment->consultant_id, $start, $start + $appointment->getDurationMinutes() * 60, $appointment->id) !== null) {
                // F23: kept as a credit the patient can use or ask back
                FeeLifecycle::creditLatePayment($fee);
                return;
            }
            $appointment->status = Appointments::STATUS_AWAITING_PAYMENT;
            $appointment->updateAttributes(['status' => Appointments::STATUS_AWAITING_PAYMENT]);
        }
        if ($appointment->isAwaitingPayment()) {
            $appointment->status = Appointments::STATUS_SCHEDULED;
            $appointment->hold_expires_at = null;
            $appointment->save(false, ['status', 'hold_expires_at', 'updated_at', 'updated_by']);
            // F24: a front-desk booking ahead earns its pending incentive
            Incentives::onFeeConfirmed($fee);
        }
    }

    /** A held booking was cancelled before it was paid: its fee is cancelled too. */
    public static function cancelPending(Appointments $appointment)
    {
        $fee = CommitmentFee::forAppointment($appointment->id);
        if ($fee !== null && $fee->state === CommitmentFee::STATE_PENDING) {
            $fee->setAttributes(['state' => CommitmentFee::STATE_CANCELLED, 'settled_at' => self::now()], false);
            $fee->save(false);
        }
    }

    /**
     * Releases bookings whose hold ran out unpaid. Each payment still pending is asked about first,
     * so one that went through is not lost.
     *
     * @return int how many were released
     */
    public static function releaseExpired()
    {
        $released = 0;
        $expired = Appointments::find()
            ->where(['status' => Appointments::STATUS_AWAITING_PAYMENT])
            ->andWhere(['<', 'hold_expires_at', self::now()])
            ->orderBy(['hold_expires_at' => SORT_ASC])
            ->limit(100)
            ->all();
        foreach ($expired as $appointment) {
            $fee = CommitmentFee::forAppointment($appointment->id);
            $transaction = $fee && $fee->mpesa_transaction_id ? MpesaTransaction::findOne($fee->mpesa_transaction_id) : null;
            if ($transaction !== null && $transaction->isPending()) {
                try {
                    Yii::$app->mpesa->refreshStk($transaction); // settles it, which may mark the fee paid
                } catch (MpesaException $e) {
                    // Safaricom did not answer: release anyway; a payment arriving later is still recorded
                }
            }
            $appointment->refresh();
            if (!$appointment->isAwaitingPayment()) {
                continue;
            }
            self::release($appointment, $fee);
            $released++;
        }

        return $released;
    }

    /** Whether the booking is awaiting payment and its hold has not run out. */
    public static function isHeld(Appointments $appointment)
    {
        return $appointment->isAwaitingPayment() && (int) $appointment->hold_expires_at >= self::now();
    }

    /** The pay page of a booking, absolute when $absolute (for messages). */
    public static function payUrl(Appointments $appointment, $absolute = false)
    {
        $path = '/pay/' . $appointment->id . '/' . SelfServiceLink::signature($appointment);
        if (!$absolute) {
            return $path;
        }

        return rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/') . $path;
    }

    /** What the pay page shows, as JSON for its polling. */
    public static function statusOf(Appointments $appointment)
    {
        $fee = CommitmentFee::forAppointment($appointment->id);
        $transaction = $fee && $fee->mpesa_transaction_id ? MpesaTransaction::findOne($fee->mpesa_transaction_id) : null;

        return [
            'status' => $appointment->status,
            'fee' => $fee ? $fee->state : null,
            'held' => self::isHeld($appointment),
            'secondsLeft' => $appointment->isAwaitingPayment() ? max(0, (int) $appointment->hold_expires_at - self::now()) : 0,
            'promptPending' => $transaction !== null && $transaction->isPending(),
            'note' => $fee ? $fee->note : null,
            'receipt' => $transaction ? $transaction->receipt : null,
        ];
    }

    private static function release(Appointments $appointment, ?CommitmentFee $fee)
    {
        $appointment->status = Appointments::STATUS_UNPAID;
        $appointment->hold_expires_at = null;
        $appointment->save(false, ['status', 'hold_expires_at', 'updated_at', 'updated_by']);
        if ($fee !== null && $fee->state === CommitmentFee::STATE_PENDING) {
            $fee->setAttributes(['state' => CommitmentFee::STATE_FAILED, 'settled_at' => self::now()], false);
            $fee->save(false);
        }
        self::tellReleased($appointment);
    }

    /** One email, and WhatsApp with the patient's consent: not completed, book again. */
    private static function tellReleased(Appointments $appointment)
    {
        $patient = User::findOne($appointment->patient_id);
        if ($patient === null) {
            return;
        }
        $consultant = $appointment->consultant;
        $when = date('D j M', strtotime($appointment->date)) . ' at ' . substr((string) $appointment->time, 0, 5);
        $doctor = $consultant->names ?? 'the doctor';
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');
        $rebook = $base . '/appointments/calendar' . ($consultant ? '?cid=' . $consultant->id : '');
        $text = "Your booking with $doctor on $when was not completed, because the Ksh " . FeeRules::amount()
            . " commitment fee was not received in time. The time has been released. You can book again here: $rebook";

        if ($patient->email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $patient->email,
                'toName' => $patient->full_name ?: null,
                'subject' => 'Your booking was not completed',
                'text' => 'Dear ' . ($patient->full_name ?: $patient->username) . ",\n\n" . $text,
                'linkUrl' => $rebook,
                'linkLabel' => 'Book again',
            ]));
        }
        if ($patient->phone_number && Consent::remindersAllowed($patient->id)) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $patient->phone_number, 'text' => $text]));
        }
    }

    /**
     * Runs $callback once the current database transaction commits, or now when there is none; not
     * at all when it rolls back. The M-Pesa prompt is an HTTP call: it must not hold the booking's
     * locks, nor go out for a booking that was then rolled back.
     */
    private static function afterCommit(callable $callback)
    {
        $db = Yii::$app->db;
        // Tests run inside a transaction that is never committed
        if ($db->getTransaction() === null || YII_ENV_TEST) {
            $callback();
            return;
        }
        $onCommit = null;
        $onRollback = null;
        $detach = function () use ($db, &$onCommit, &$onRollback) {
            $db->off(Connection::EVENT_COMMIT_TRANSACTION, $onCommit);
            $db->off(Connection::EVENT_ROLLBACK_TRANSACTION, $onRollback);
        };
        $onCommit = function () use ($callback, $detach) {
            $detach();
            $callback();
        };
        $onRollback = function () use ($detach) {
            $detach();
        };
        $db->on(Connection::EVENT_COMMIT_TRANSACTION, $onCommit);
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, $onRollback);
    }

    private static function now()
    {
        return self::$now ?? time();
    }
}
