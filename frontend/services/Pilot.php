<?php

namespace frontend\services;

use frontend\models\Consultant;

/**
 * F20: where a consultant is in their 90-day pilot (docs/business-case.md). The pilot starts on the
 * day of their first booking on the platform, by any channel (consultant.first_booking_at, set
 * once by Appointments::afterSave()), not when the facility signed. Day 1 is that day.
 *
 * - days 1–30, PHASE_BASELINE: no fees; the no-show baseline
 * - days 31–60, PHASE_FEES: the commitment fee and front-desk incentive (Plan A)
 * - days 61–90, PHASE_FULL: full operation
 * - day 91 on, PHASE_STANDARD: standard terms, once a platform admin confirms the facility's move
 */
class Pilot
{
    const PHASE_NOT_STARTED = 'not_started';
    const PHASE_BASELINE = 'baseline';
    const PHASE_FEES = 'fees';
    const PHASE_FULL = 'full';
    const PHASE_STANDARD = 'standard';

    /**
     * The pilot day on $at (a timestamp; now by default): 1 on the day of the first booking. Null
     * before the first booking. Counted in calendar days in the app's time zone.
     */
    public static function day(Consultant $consultant, $at = null)
    {
        if (!$consultant->first_booking_at) {
            return null;
        }
        $start = new \DateTimeImmutable('@' . (int) $consultant->first_booking_at);
        $end = new \DateTimeImmutable('@' . (int) ($at ?? time()));
        $zone = new \DateTimeZone(date_default_timezone_get());
        $start = $start->setTimezone($zone)->setTime(0, 0);
        $end = $end->setTimezone($zone)->setTime(0, 0);
        if ($end < $start) {
            return null;
        }

        return (int) $start->diff($end)->days + 1;
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
        if ($day <= 90) {
            return self::PHASE_FULL;
        }

        return self::PHASE_STANDARD;
    }

    public static function phaseLabels()
    {
        return [
            self::PHASE_NOT_STARTED => 'Not started (no booking yet)',
            self::PHASE_BASELINE => 'Days 1–30: baseline, no fees',
            self::PHASE_FEES => 'Days 31–60: fees and incentives',
            self::PHASE_FULL => 'Days 61–90: full operation',
            self::PHASE_STANDARD => 'Day 91 on: standard terms',
        ];
    }
}
