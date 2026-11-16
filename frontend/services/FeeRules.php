<?php

namespace frontend\services;

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\FacilityAgreement;
use Yii;

/**
 * F22: whether a new booking carries the Ksh 250 commitment fee, and who pays it
 * (docs/business-case.md, "Booking with a commitment fee"). In order:
 *
 * 1. Fees are switched off (COMMITMENT_FEE_ENABLED=0): none.
 * 2. The consultant is on Plan B: none.
 * 3. The consultant is in days 1–30 of their pilot, or has no booking yet: none.
 * 4. A same-day walk-in at reception, where the facility's agreement exempts them (the default,
 *    also without an agreement): none. Same-day bookings online or by link still pay.
 * 5. The patient has already paid, or is paying, a fee for that day, with any consultant: none.
 * 6. An insured patient of a consultant who does not ask insured patients to pay: absorbed by the
 *    consultant, billed to them, with no M-Pesa prompt.
 * 7. Otherwise: the patient pays by M-Pesa.
 */
class FeeRules
{
    const NONE = 'none';
    const CHARGE = 'charge';
    const ABSORB = 'absorb';

    const PLAN_A = 'A';
    const PLAN_B = 'B';

    const PAYMENT_CASH = 'cash';
    const PAYMENT_INSURANCE = 'insurance';

    public static function enabled()
    {
        return !empty(Yii::$app->params['commitmentFee']['enabled']);
    }

    public static function amount()
    {
        return (int) (Yii::$app->params['commitmentFee']['amount'] ?? 250);
    }

    /**
     * The decision for a booking, made when it is booked: [NONE|CHARGE|ABSORB, reason]. The
     * booking's channel and facility must be set (they are once it has been validated).
     *
     * @param int|null $at when it is booked (now by default); decides the pilot day
     * @return array [string $decision, string $reason]
     */
    public static function decide(Appointments $appointment, $at = null)
    {
        if (!self::enabled()) {
            return [self::NONE, 'Commitment fees are switched off.'];
        }
        $consultant = Consultant::findOne(['user_id' => $appointment->consultant_id]);
        if ($consultant === null) {
            return [self::NONE, 'No consultant.'];
        }
        if ($consultant->plan === self::PLAN_B) {
            return [self::NONE, 'The consultant is on Plan B.'];
        }
        $day = Pilot::day($consultant, $at);
        if ($day === null || $day <= 30) {
            return [self::NONE, 'Days 1–30 of the consultant\'s pilot: no fees.'];
        }
        if ($appointment->booking_channel === Appointments::CHANNEL_WALK_IN && self::walkInsExempt($appointment->facility_id)) {
            return [self::NONE, 'A same-day walk-in at reception.'];
        }
        if (self::patientPaysThatDay($appointment)) {
            return [self::NONE, 'The patient already pays a fee for that day.'];
        }
        if ($appointment->payment_mode === self::PAYMENT_INSURANCE && !$consultant->insured_pay) {
            return [self::ABSORB, 'An insured patient: the consultant absorbs the fee.'];
        }

        return [self::CHARGE, 'The patient pays the commitment fee.'];
    }

    /** Whether the facility exempts same-day walk-ins: as its agreement says; yes without one. */
    public static function walkInsExempt($facilityId)
    {
        $agreement = $facilityId ? FacilityAgreement::currentFor($facilityId) : null;

        return $agreement === null || (bool) $agreement->walk_in_exempt;
    }

    /** Whether the patient has paid, or is paying, a fee for the appointment's date. */
    public static function patientPaysThatDay(Appointments $appointment)
    {
        $query = CommitmentFee::find()->where([
            'patient_id' => $appointment->patient_id,
            'fee_date' => $appointment->date,
            'payer' => CommitmentFee::PAYER_PATIENT,
            'state' => CommitmentFee::PATIENT_PAYING_STATES,
        ]);
        if ($appointment->id) {
            $query->andWhere(['<>', 'appointment_id', $appointment->id]);
        }

        return $query->exists();
    }

    public static function planLabels()
    {
        return [
            self::PLAN_A => 'Plan A: commitment fee',
            self::PLAN_B => 'Plan B: flat',
        ];
    }

    public static function paymentModeLabels()
    {
        return [
            self::PAYMENT_CASH => Yii::t('app', 'Cash (I pay myself)'),
            self::PAYMENT_INSURANCE => Yii::t('app', 'Insurance'),
        ];
    }
}
