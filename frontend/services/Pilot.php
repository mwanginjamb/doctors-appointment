<?php

namespace frontend\services;

use common\components\RbacHelper;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\FacilityAgreement;
use Yii;

/**
 * F20: where a consultant is in their 90-day pilot (docs/business-case.md). The pilot starts on the
 * day of their first booking on the platform, by any channel (consultant.first_booking_at, set
 * once by Appointments::afterSave()), not when the facility signed. Day 1 is that day.
 *
 * - days 1–30, PHASE_BASELINE: no fees; the no-show baseline
 * - days 31–60, PHASE_FEES: the commitment fee and front-desk incentive (Plan A)
 * - days 61–90, PHASE_FULL: full operation
 * - day 91 on, PHASE_STANDARD: by the day count alone. Charging on standard terms also needs the
 *   facility's terms to be confirmed and approved: see onStandardTerms().
 *
 * Standard terms are settled per facility, on the clock of its earliest pilot (facilityDay()), in
 * two steps: the facility's front desk or facility admin confirms, then a platform admin approves.
 * A notice goes out on day 81, another on day 91 when a 30-day grace period starts, and the
 * facility sees a warning in the app until it confirms. After the grace period the warning stays
 * and platform admins are told. Nothing is charged until the terms are approved.
 */
class Pilot
{
    const PHASE_NOT_STARTED = 'not_started';
    const PHASE_BASELINE = 'baseline';
    const PHASE_FEES = 'fees';
    const PHASE_FULL = 'full';
    const PHASE_STANDARD = 'standard';

    const PILOT_DAYS = 90;
    /** The first notice goes this many days before standard terms start (day 81) */
    const NOTICE_DAYS = 10;
    /** Days after day 90 to confirm before platform admins are told */
    const GRACE_DAYS = 30;

    /** A facility's standard terms, see termsStatus() */
    const TERMS_NOT_DUE = 'not_due';        // no pilot, or before day 81
    const TERMS_UPCOMING = 'upcoming';      // days 81–90, not confirmed
    const TERMS_GRACE = 'grace';            // days 91–120, not confirmed
    const TERMS_OVERDUE = 'overdue';        // day 121 on, not confirmed
    const TERMS_AWAITING_APPROVAL = 'awaiting_approval'; // the facility confirmed; a platform admin approves
    const TERMS_APPROVED = 'approved';      // standard terms apply

    /**
     * The pilot day on $at (a timestamp; now by default): 1 on the day of the first booking. Null
     * before the first booking. Counted in calendar days in the app's time zone.
     */
    public static function day(Consultant $consultant, $at = null)
    {
        return self::dayFrom($consultant->first_booking_at, $at);
    }

    /** The phase on $at; PHASE_NOT_STARTED before the first booking. */
    public static function phase(Consultant $consultant, $at = null)
    {
        $day = self::day($consultant, $at);
        if ($day === null) {
            return self::PHASE_NOT_STARTED;
        }
        if ($day <= 30) {
            return self::PHASE_BASELINE;
        }
        if ($day <= 60) {
            return self::PHASE_FEES;
        }
        if ($day <= self::PILOT_DAYS) {
            return self::PHASE_FULL;
        }

        return self::PHASE_STANDARD;
    }

    /**
     * Whether the consultant's work at the facility is charged on standard terms (plan charge, the
     * Ksh 100 cancellation charge): past their own day 90, and the facility's terms confirmed by
     * the facility and approved by a platform admin.
     */
    public static function onStandardTerms(Consultant $consultant, $facilityId, $at = null)
    {
        if (self::phase($consultant, $at) !== self::PHASE_STANDARD) {
            return false;
        }

        return self::termsStatus($facilityId, $at) === self::TERMS_APPROVED;
    }

    /** The start of the facility's earliest pilot: the first booking of any consultant engaged there. */
    public static function facilityStart($facilityId)
    {
        $start = Consultant::find()
            ->innerJoin(ConsultantFacility::tableName() . ' cf', 'cf.consultant_id = consultant.id')
            ->where(['cf.facility_id' => (int) $facilityId])
            ->min('consultant.first_booking_at');

        return $start ? (int) $start : null;
    }

    /** The facility's pilot day: that of its earliest pilot. Null before any booking there. */
    public static function facilityDay($facilityId, $at = null)
    {
        return self::dayFrom(self::facilityStart($facilityId), $at);
    }

    /**
     * Where the facility is with standard terms on $at: TERMS_APPROVED once a platform admin
     * approved, TERMS_AWAITING_APPROVAL once the facility confirmed; otherwise by its pilot day.
     */
    public static function termsStatus($facilityId, $at = null)
    {
        $agreement = FacilityAgreement::currentFor($facilityId);
        if ($agreement !== null && $agreement->standard_terms_approved_at !== null) {
            return self::TERMS_APPROVED;
        }
        if ($agreement !== null && $agreement->standard_terms_confirmed_at !== null) {
            return self::TERMS_AWAITING_APPROVAL;
        }
        $day = self::facilityDay($facilityId, $at);
        if ($day === null || $day <= self::PILOT_DAYS - self::NOTICE_DAYS) {
            return self::TERMS_NOT_DUE;
        }
        if ($day <= self::PILOT_DAYS) {
            return self::TERMS_UPCOMING;
        }

        return $day <= self::PILOT_DAYS + self::GRACE_DAYS ? self::TERMS_GRACE : self::TERMS_OVERDUE;
    }

    /** The date standard terms start at the facility (its day 91), Y-m-d; null before any booking. */
    public static function standardTermsStart($facilityId)
    {
        $start = self::facilityStart($facilityId);

        return $start ? date('Y-m-d', strtotime('+' . self::PILOT_DAYS . ' days', $start)) : null;
    }

    /** The last day of the grace period (day 120), Y-m-d. */
    public static function graceEnds($facilityId)
    {
        $start = self::facilityStart($facilityId);

        return $start ? date('Y-m-d', strtotime('+' . (self::PILOT_DAYS + self::GRACE_DAYS - 1) . ' days', $start)) : null;
    }

    /**
     * The facilities a user works with: a consultant's engagements, a front desk's or facility
     * admin's own facility. Empty for patients and platform admins.
     *
     * @param \common\models\User $identity
     * @return int[]
     */
    public static function facilitiesOf($identity)
    {
        if ($identity->isConsultantAccount()) {
            return array_map('intval', ConsultantFacility::find()->alias('cf')
                ->innerJoin(Consultant::tableName() . ' c', 'c.id = cf.consultant_id')
                ->where(['c.user_id' => $identity->id])->select('cf.facility_id')->column());
        }
        if ($identity->isFrontDeskAccount() || $identity->isFacilityAdminAccount()) {
            return $identity->facility_id ? [(int) $identity->facility_id] : [];
        }

        return [];
    }

    /**
     * Who may confirm the facility's standard terms (step 1): its facility admin and front desk,
     * and platform admins acting for it.
     *
     * @param \common\models\User|null $identity
     */
    public static function canConfirm($identity, $facilityId)
    {
        if ($identity === null) {
            return false;
        }
        if (Yii::$app->authManager->checkAccess($identity->id, RbacHelper::ROLE_ADMIN)) {
            return true;
        }

        return ($identity->isFrontDeskAccount() || $identity->isFacilityAdminAccount())
            && (int) $identity->facility_id === (int) $facilityId;
    }

    /** Who may approve standard terms once the facility confirmed: platform admins. */
    public static function canApprove($identity)
    {
        return $identity !== null && Yii::$app->authManager->checkAccess($identity->id, RbacHelper::ROLE_ADMIN);
    }

    /** Who may read the facility's terms page: those who may confirm, and its consultants. */
    public static function canView($identity, $facilityId)
    {
        return self::canConfirm($identity, $facilityId)
            || ($identity !== null && in_array((int) $facilityId, self::facilitiesOf($identity), true));
    }

    public static function phaseLabels()
    {
        return [
            self::PHASE_NOT_STARTED => 'Not started (no booking yet)',
            self::PHASE_BASELINE => 'Days 1–30: baseline, no fees',
            self::PHASE_FEES => 'Days 31–60: fees and incentives',
            self::PHASE_FULL => 'Days 61–90: full operation',
            self::PHASE_STANDARD => 'Day 91 on: standard terms (charged once the facility confirms)',
        ];
    }

    public static function termsLabels()
    {
        return [
            self::TERMS_NOT_DUE => 'Not due yet',
            self::TERMS_UPCOMING => 'Standard terms start within 10 days',
            self::TERMS_GRACE => 'Waiting for confirmation (grace period)',
            self::TERMS_OVERDUE => 'Not confirmed after the grace period',
            self::TERMS_AWAITING_APPROVAL => 'Confirmed by the facility, waiting for platform approval',
            self::TERMS_APPROVED => 'Approved: standard terms apply',
        ];
    }

    private static function dayFrom($startTs, $at)
    {
        if (!$startTs) {
            return null;
        }
        $zone = new \DateTimeZone(date_default_timezone_get());
        $start = (new \DateTimeImmutable('@' . (int) $startTs))->setTimezone($zone)->setTime(0, 0);
        $end = (new \DateTimeImmutable('@' . (int) ($at ?? time())))->setTimezone($zone)->setTime(0, 0);
        if ($end < $start) {
            return null;
        }

        return (int) $start->diff($end)->days + 1;
    }
}
