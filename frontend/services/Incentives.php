<?php

namespace frontend\services;

use common\components\MpesaException;
use common\helpers\Phone;
use common\models\MpesaTransaction;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\FrontdeskIncentive;
use frontend\models\IncentivePayout;
use frontend\models\User;
use Yii;

/**
 * F24: the Ksh 50 front-desk incentive (docs/business-case.md).
 *
 * - Created **pending** when the commitment fee of a booking a front-desk user made ahead (a staff
 *   booking, not a same-day walk-in) is paid or absorbed. Fees exist only on Plan A, from day 31.
 * - **Earned** when the patient attends.
 * - **Cancelled** on a no-show or any cancellation; and at once, **flagged**, when the same
 *   front-desk user cancelled an appointment of the same patient in the 24 hours before booking it
 *   (cancel and rebook). A no-show corrected to attended earns it again.
 * - **Paid weekly**: every Monday, buildWeeklyPayouts() groups each front-desk user's earned
 *   incentives up to the Sunday into one payout, to the phone on their account. A platform admin
 *   sends it by M-Pesa B2C (MPESA_B2C_PAYOUTS_ENABLED) or pays by hand and records the reference.
 */
class Incentives
{
    const LINK_TYPE = 'incentive_payout';

    /** @var int|null overrides time() in tests */
    public static $now;

    public static function amount()
    {
        return (int) (Yii::$app->params['commitmentFee']['frontDeskIncentive'] ?? 50);
    }

    /** A booking's fee was paid or absorbed: a front-desk booking ahead earns a pending incentive. */
    public static function onFeeConfirmed(CommitmentFee $fee)
    {
        if (!in_array($fee->state, [CommitmentFee::STATE_PAID, CommitmentFee::STATE_ABSORBED], true)
            || FrontdeskIncentive::forAppointment($fee->appointment_id) !== null) {
            return null;
        }
        $appointment = $fee->appointment;
        if ($appointment === null || $appointment->booking_channel !== Appointments::CHANNEL_STAFF || !$appointment->created_by) {
            return null;
        }
        $frontDesk = User::findOne($appointment->created_by);
        if ($frontDesk === null || !$frontDesk->isFrontDeskAccount()) {
            return null;
        }

        $incentive = new FrontdeskIncentive([
            'appointment_id' => $appointment->id,
            'fee_id' => $fee->id,
            'front_desk_id' => $frontDesk->id,
            'patient_id' => $appointment->patient_id,
            'facility_id' => $appointment->facility_id,
            'amount' => self::amount(),
            'state' => FrontdeskIncentive::STATE_PENDING,
        ]);
        $rebooked = self::recentCancellationBy($frontDesk->id, $appointment);
        if ($rebooked !== null) {
            $incentive->setAttributes([
                'state' => FrontdeskIncentive::STATE_CANCELLED,
                'flagged' => 1,
                'note' => "Cancel and rebook: the same front desk cancelled appointment #{$rebooked->id} of this patient within 24 hours.",
            ], false);
        }
        $incentive->save(false);

        return $incentive;
    }

    /** The appointment was cancelled: its incentive goes, unless already paid. */
    public static function onCancelled(Appointments $appointment)
    {
        self::cancel($appointment, 'The appointment was cancelled.');
    }

    /** Attended earns the incentive; a no-show cancels it (both can be corrected). */
    public static function onOutcome(Appointments $appointment)
    {
        $incentive = FrontdeskIncentive::forAppointment($appointment->id);
        if ($incentive === null || $incentive->flagged) {
            return;
        }
        if ($appointment->status === Appointments::STATUS_COMPLETED
            && ($incentive->state === FrontdeskIncentive::STATE_PENDING
                || ($incentive->state === FrontdeskIncentive::STATE_CANCELLED && $incentive->note === 'The patient did not come.'))) {
            $incentive->setAttributes(['state' => FrontdeskIncentive::STATE_EARNED, 'earned_at' => self::now(), 'note' => null], false);
            $incentive->save(false);
        } elseif ($appointment->status === Appointments::STATUS_NO_SHOW) {
            self::cancel($appointment, 'The patient did not come.');
        }
    }

    /**
     * Groups each front-desk user's earned incentives, up to the Sunday before $at, into a payout
     * for that week (Monday to Sunday). Earlier stragglers join it. Run every Monday morning.
     *
     * @return IncentivePayout[] the payouts made or added to
     */
    public static function buildWeeklyPayouts($at = null)
    {
        $at = $at ?? self::now();
        $periodStart = date('Y-m-d', strtotime('monday last week', $at));
        $periodEnd = date('Y-m-d', strtotime($periodStart . ' +6 days'));
        $until = strtotime($periodEnd . ' +1 day');

        $earned = FrontdeskIncentive::find()
            ->where(['state' => FrontdeskIncentive::STATE_EARNED, 'payout_id' => null])
            ->andWhere(['<', 'earned_at', $until])
            ->orderBy(['front_desk_id' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
        $byUser = [];
        foreach ($earned as $incentive) {
            $byUser[$incentive->front_desk_id][] = $incentive;
        }

        $payouts = [];
        foreach ($byUser as $userId => $incentives) {
            $payout = IncentivePayout::findOne(['front_desk_id' => $userId, 'period_start' => $periodStart]);
            if ($payout !== null && $payout->status === IncentivePayout::STATUS_PAID) {
                continue; // a late addition waits for next week's payout
            }
            if ($payout === null) {
                $payout = new IncentivePayout(['front_desk_id' => $userId, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'amount' => 0, 'incentives' => 0]);
            }
            $payout->amount += array_sum(array_map(function ($i) { return (int) $i->amount; }, $incentives));
            $payout->incentives += count($incentives);
            self::setPhone($payout);
            $payout->save(false);
            FrontdeskIncentive::updateAll(['payout_id' => $payout->id, 'updated_at' => self::now()], ['id' => array_map(function ($i) { return $i->id; }, $incentives)]);
            $payouts[] = $payout;
        }

        return $payouts;
    }

    /** Looks at the account's phone again: a held payout becomes ready once it has a Kenyan number. */
    public static function refreshPhone(IncentivePayout $payout)
    {
        if (!in_array($payout->status, [IncentivePayout::STATUS_READY, IncentivePayout::STATUS_HELD], true)) {
            return false;
        }
        self::setPhone($payout);

        return $payout->save(false);
    }

    /**
     * A platform admin sends a ready payout by M-Pesa B2C (MPESA_B2C_PAYOUTS_ENABLED). False when it
     * is not ready, B2C is off, or the send failed (see the note).
     */
    public static function send(IncentivePayout $payout, $adminId)
    {
        if ($payout->status !== IncentivePayout::STATUS_READY || !FeeLifecycle::b2cEnabled()) {
            return false;
        }
        try {
            $transaction = Yii::$app->mpesa->b2cPayment($payout->phone, $payout->amount, 'Front desk incentive',
                'Incentive', ['type' => self::LINK_TYPE, 'id' => $payout->id]);
        } catch (MpesaException | \InvalidArgumentException $e) {
            $payout->note = mb_substr('B2C failed: ' . $e->getMessage(), 0, 255);
            $payout->save(false);
            return false;
        }
        $payout->setAttributes(['status' => IncentivePayout::STATUS_SENDING, 'mpesa_transaction_id' => $transaction->id, 'paid_by' => $adminId, 'note' => null], false);
        $payout->save(false);
        if ($transaction->isSettled()) {
            self::onPayoutSettled($transaction);
        }

        return true;
    }

    /** A payout paid by hand: recorded with its M-Pesa reference. */
    public static function markPaid(IncentivePayout $payout, $reference, $adminId)
    {
        $reference = strtoupper(trim((string) $reference));
        if (!in_array($payout->status, [IncentivePayout::STATUS_READY, IncentivePayout::STATUS_HELD], true)
            || !preg_match('/^[A-Z0-9]{6,20}$/', $reference)) {
            return false;
        }
        self::paid($payout, $reference, $adminId);

        return true;
    }

    /** Mpesa::EVENT_SETTLED for a payout's B2C payment. */
    public static function onPayoutSettled(MpesaTransaction $transaction)
    {
        $payout = IncentivePayout::findOne((int) $transaction->link_id);
        if ($payout === null || $payout->status !== IncentivePayout::STATUS_SENDING) {
            return;
        }
        if ($transaction->status === MpesaTransaction::STATUS_SUCCEEDED) {
            self::paid($payout, $transaction->receipt, $payout->paid_by);
        } else {
            $payout->setAttributes(['status' => IncentivePayout::STATUS_READY, 'note' => mb_substr('B2C did not go through: ' . $transaction->result_desc, 0, 255)], false);
            $payout->save(false);
        }
    }

    private static function paid(IncentivePayout $payout, $reference, $adminId)
    {
        $payout->setAttributes(['status' => IncentivePayout::STATUS_PAID, 'reference' => $reference, 'paid_at' => self::now(), 'paid_by' => $adminId], false);
        $payout->save(false);
        FrontdeskIncentive::updateAll(['state' => FrontdeskIncentive::STATE_PAID, 'updated_at' => self::now()],
            ['payout_id' => $payout->id, 'state' => FrontdeskIncentive::STATE_EARNED]);
    }

    private static function setPhone(IncentivePayout $payout)
    {
        $phone = Phone::normalize(User::find()->select('phone_number')->where(['id' => $payout->front_desk_id])->scalar() ?: '');
        $kenyan = $phone !== null && strpos($phone, Phone::DEFAULT_COUNTRY) === 0;
        $payout->phone = $kenyan ? $phone : null;
        $payout->status = $kenyan ? IncentivePayout::STATUS_READY : IncentivePayout::STATUS_HELD;
    }

    private static function cancel(Appointments $appointment, $note)
    {
        $incentive = FrontdeskIncentive::forAppointment($appointment->id);
        if ($incentive !== null && in_array($incentive->state, [FrontdeskIncentive::STATE_PENDING, FrontdeskIncentive::STATE_EARNED], true)
            && $incentive->payout_id === null) {
            $incentive->setAttributes(['state' => FrontdeskIncentive::STATE_CANCELLED, 'note' => $note], false);
            $incentive->save(false);
        }
    }

    /** An appointment of the same patient this front desk cancelled in the 24 hours before now. */
    private static function recentCancellationBy($frontDeskId, Appointments $booking)
    {
        return Appointments::find()
            ->where(['patient_id' => $booking->patient_id, 'cancelled_by' => $frontDeskId, 'status' => Appointments::STATUS_CANCELLED])
            ->andWhere(['>=', 'cancelled_at', self::now() - 86400])
            ->andWhere(['<>', 'id', $booking->id])
            ->one();
    }

    private static function now()
    {
        return self::$now ?? time();
    }
}
