<?php

namespace frontend\services;

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FrontdeskIncentive;
use frontend\models\PatientCredit;
use yii\db\Query;

/**
 * F26: the 90-day pilot report (docs/business-case.md). Each consultant's month 1 (days 1–30, no
 * fees: the no-show baseline) against their months 2 (31–60) and 3 (61–90), counted from their own
 * day 1 (their first booking) and by the appointment's date; added up per facility.
 *
 * Per month: bookings; attended and no-shows (the rate is of appointments whose outcome was
 * recorded; unrecorded ones are counted apart); late cancellations (the patient asked, less than
 * 24 hours before); booked ahead (online, link, staff) against walk-ins; commitment fees collected,
 * absorbed, forfeited, credited and refunded; front-desk incentives earned; consultation fees on
 * attended visits. And an ROI estimate (see roi()).
 */
class PilotReport
{
    /** month => [first day, last day] of the pilot */
    const MONTHS = [1 => [1, 30], 2 => [31, 60], 3 => [61, 90]];

    /** @var string|null Y-m-d, overrides today in tests */
    public static $today;

    private static function today()
    {
        return self::$today ?? date('Y-m-d');
    }

    /**
     * The dates each month covers for this consultant, and how far it has got.
     *
     * @return array month => ['from' => Y-m-d, 'to' => Y-m-d, 'status' => 'done'|'current'|'future', 'day' => int|null]
     */
    public static function months(Consultant $consultant)
    {
        if (!$consultant->first_booking_at) {
            return [];
        }
        $start = date('Y-m-d', (int) $consultant->first_booking_at);
        $today = self::today();
        $day = (int) floor((strtotime($today) - strtotime($start)) / 86400) + 1;
        $out = [];
        foreach (self::MONTHS as $month => [$first, $last]) {
            $from = date('Y-m-d', strtotime($start . ' +' . ($first - 1) . ' days'));
            $to = date('Y-m-d', strtotime($start . ' +' . ($last - 1) . ' days'));
            $status = $today > $to ? 'done' : ($today >= $from ? 'current' : 'future');
            $out[$month] = ['from' => $from, 'to' => $to, 'status' => $status, 'day' => $status === 'current' ? $day : null];
        }

        return $out;
    }

    /**
     * The figures for one consultant, at one facility (or all theirs).
     *
     * @return array ['consultant', 'start' => Y-m-d|null, 'day' => int|null, 'months' => month => figures, 'roi' => array|null]
     */
    public static function forConsultant(Consultant $consultant, $facilityId = null)
    {
        $months = [];
        foreach (self::months($consultant) as $month => $range) {
            $months[$month] = self::figures((int) $consultant->user_id, $facilityId, $range['from'], $range['to']) + $range;
        }

        return [
            'consultant' => $consultant,
            'start' => $consultant->first_booking_at ? date('Y-m-d', (int) $consultant->first_booking_at) : null,
            'day' => Pilot::day($consultant),
            'months' => $months,
            'roi' => $months ? self::roi($months) : null,
        ];
    }

    /**
     * The facility: each consultant there whose pilot has started (their bookings at this facility),
     * and the months added up across them.
     *
     * @param int[]|null $onlyConsultantIds consultant.id values to include (a consultant sees only their own)
     * @return array ['facility', 'consultants' => forConsultant()[], 'total' => month => figures, 'roi']
     */
    public static function forFacility(Facility $facility, ?array $onlyConsultantIds = null)
    {
        $query = Consultant::find()->alias('c')
            ->innerJoin(ConsultantFacility::tableName() . ' cf', 'cf.consultant_id = c.id')
            ->where(['cf.facility_id' => $facility->id])->andWhere(['not', ['c.first_booking_at' => null]])
            ->orderBy(['c.names' => SORT_ASC]);
        if ($onlyConsultantIds !== null) {
            $query->andWhere(['c.id' => $onlyConsultantIds ?: [0]]);
        }
        $consultants = [];
        $total = [];
        foreach ($query->all() as $consultant) {
            $row = self::forConsultant($consultant, $facility->id);
            $consultants[] = $row;
            foreach ($row['months'] as $month => $figures) {
                $total[$month] = isset($total[$month]) ? self::add($total[$month], $figures) : $figures;
            }
        }
        foreach ($total as $month => $figures) {
            // Done when every consultant's month is over; under way while any is (no single day then)
            $statuses = array_unique(array_map(function ($row) use ($month) { return $row['months'][$month]['status'] ?? 'future'; }, $consultants));
            $figures['status'] = count($statuses) === 1 ? reset($statuses) : 'current';
            $figures['day'] = null;
            $figures['from'] = min(array_map(function ($row) use ($month) { return $row['months'][$month]['from']; }, $consultants));
            $figures['to'] = max(array_map(function ($row) use ($month) { return $row['months'][$month]['to']; }, $consultants));
            $total[$month] = self::rates($figures);
        }

        return ['facility' => $facility, 'consultants' => $consultants, 'total' => $total, 'roi' => $total ? self::roi($total) : null];
    }

    /** Facilities with at least one consultant whose pilot has started, by name. */
    public static function facilities(?array $ids = null)
    {
        $query = Facility::find()->alias('f')
            ->innerJoin(ConsultantFacility::tableName() . ' cf', 'cf.facility_id = f.id')
            ->innerJoin(Consultant::tableName() . ' c', 'c.id = cf.consultant_id')
            ->where(['not', ['c.first_booking_at' => null]])
            ->distinct()->orderBy(['f.name' => SORT_ASC]);
        if ($ids !== null) {
            $query->andWhere(['f.id' => $ids ?: [0]]);
        }

        return $query->all();
    }

    /** Counts for a consultant (user id) between two dates, at a facility or all. */
    private static function figures($consultantUserId, $facilityId, $from, $to)
    {
        $where = ['and', ['a.consultant_id' => $consultantUserId], ['between', 'a.date', $from, $to],
            ['or', ['a.status' => null], ['not in', 'a.status', [Appointments::STATUS_AWAITING_PAYMENT, Appointments::STATUS_UNPAID]]]];
        if ($facilityId !== null) {
            $where[] = ['a.facility_id' => (int) $facilityId];
        }
        $today = self::today();
        $row = (new Query())->from(['a' => Appointments::tableName()])->where($where)->select([
            'bookings' => 'COUNT(*)',
            'cancelled' => 'SUM(CASE WHEN a.status = :cancelled THEN 1 ELSE 0 END)',
            'attended' => 'SUM(CASE WHEN a.status = :completed THEN 1 ELSE 0 END)',
            'noShows' => 'SUM(CASE WHEN a.status = :noShow THEN 1 ELSE 0 END)',
            'unmarked' => 'SUM(CASE WHEN (a.status IS NULL OR a.status IN (:scheduled, :confirmed)) AND a.date < :today THEN 1 ELSE 0 END)',
            'lateCancels' => 'SUM(CASE WHEN a.status = :cancelled AND a.cancelled_at_patient_request = 1 AND a.cancelled_at IS NOT NULL'
                . ' AND UNIX_TIMESTAMP(CONCAT(a.date, \' \', a.time)) - a.cancelled_at < 86400 THEN 1 ELSE 0 END)',
            'ahead' => 'SUM(CASE WHEN a.booking_channel IN (:online, :link, :staff) THEN 1 ELSE 0 END)',
            'walkIns' => 'SUM(CASE WHEN a.booking_channel = :walkIn THEN 1 ELSE 0 END)',
            'consultationFees' => 'SUM(CASE WHEN a.status = :completed THEN COALESCE(a.fee, 0) ELSE 0 END)',
            'feeVisits' => 'SUM(CASE WHEN a.status = :completed AND a.fee IS NOT NULL THEN 1 ELSE 0 END)',
        ])->addParams([
            ':cancelled' => Appointments::STATUS_CANCELLED, ':completed' => Appointments::STATUS_COMPLETED,
            ':noShow' => Appointments::STATUS_NO_SHOW, ':scheduled' => Appointments::STATUS_SCHEDULED,
            ':confirmed' => Appointments::STATUS_CONFIRMED, ':today' => $today,
            ':online' => Appointments::CHANNEL_ONLINE, ':link' => Appointments::CHANNEL_LINK,
            ':staff' => Appointments::CHANNEL_STAFF, ':walkIn' => Appointments::CHANNEL_WALK_IN,
        ])->one();
        $figures = array_map(function ($v) { return (float) $v; }, $row ?: []);

        // Commitment fees and incentives, by the visit's date
        $feeWhere = ['and', ['f.consultant_id' => $consultantUserId], ['between', 'f.fee_date', $from, $to]];
        if ($facilityId !== null) {
            $feeWhere[] = ['f.facility_id' => (int) $facilityId];
        }
        $fees = (new Query())->from(['f' => CommitmentFee::tableName()])->where($feeWhere)->select([
            'collected' => 'SUM(CASE WHEN f.payer = :patient AND f.state NOT IN (:pending, :failed) THEN f.amount ELSE 0 END)',
            'absorbed' => 'SUM(CASE WHEN f.payer = :consultant AND f.state IN (:absorbed, :forfeited) THEN f.amount ELSE 0 END)',
            'forfeited' => 'SUM(CASE WHEN f.state = :forfeited THEN f.amount ELSE 0 END)',
            'credited' => 'SUM(CASE WHEN f.state IN (:credited, :refunded) THEN f.amount ELSE 0 END)',
        ])->addParams([
            ':patient' => CommitmentFee::PAYER_PATIENT, ':consultant' => CommitmentFee::PAYER_CONSULTANT,
            ':pending' => CommitmentFee::STATE_PENDING, ':failed' => CommitmentFee::STATE_FAILED,
            ':absorbed' => CommitmentFee::STATE_ABSORBED, ':forfeited' => CommitmentFee::STATE_FORFEITED,
            ':credited' => CommitmentFee::STATE_CREDITED, ':refunded' => CommitmentFee::STATE_REFUNDED,
        ])->one();
        $refunded = (new Query())->from(['p' => PatientCredit::tableName()])
            ->innerJoin(['f' => CommitmentFee::tableName()], 'f.id = p.source_fee_id')
            ->where($feeWhere)->andWhere(['p.status' => PatientCredit::STATUS_REFUNDED])->sum('p.amount');
        $incWhere = ['and', ['a.consultant_id' => $consultantUserId], ['between', 'a.date', $from, $to],
            ['i.state' => [FrontdeskIncentive::STATE_EARNED, FrontdeskIncentive::STATE_PAID]]];
        if ($facilityId !== null) {
            $incWhere[] = ['a.facility_id' => (int) $facilityId];
        }
        $incentives = (new Query())->from(['i' => FrontdeskIncentive::tableName()])
            ->innerJoin(['a' => Appointments::tableName()], 'a.id = i.appointment_id')->where($incWhere)->sum('i.amount');

        foreach (['collected', 'absorbed', 'forfeited', 'credited'] as $key) {
            $figures['fees' . ucfirst($key)] = (float) ($fees[$key] ?? 0);
        }
        $figures['feesRefunded'] = (float) $refunded;
        $figures['incentives'] = (float) $incentives;

        return self::rates($figures);
    }

    /** Adds the counts and money; the rates are worked out again after, and the day is not a count. */
    private static function add(array $a, array $b)
    {
        foreach ($b as $key => $value) {
            if (in_array($key, ['noShowRate', 'lateCancelRate', 'aheadShare', 'day'], true)) {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $a[$key] = ($a[$key] ?? 0) + $value;
            }
        }

        return $a;
    }

    /** Adds the rates: no-show rate (of recorded outcomes), late cancellations per 100, share booked ahead. */
    private static function rates(array $f)
    {
        $outcomes = ($f['attended'] ?? 0) + ($f['noShows'] ?? 0);
        $f['noShowRate'] = $outcomes > 0 ? $f['noShows'] / $outcomes : null;
        $f['lateCancelRate'] = ($f['bookings'] ?? 0) > 0 ? 100 * $f['lateCancels'] / $f['bookings'] : null;
        $channelled = ($f['ahead'] ?? 0) + ($f['walkIns'] ?? 0);
        $f['aheadShare'] = $channelled > 0 ? $f['ahead'] / $channelled : null;

        return $f;
    }

    /**
     * The ROI estimate: consultation revenue kept by fewer no-shows, plus commitment fees forfeited
     * by no-shows and late cancellations, over months 2 and 3.
     *
     *   visits kept = (month-1 no-show rate − months 2–3 rate) × months 2–3 visits with an outcome
     *   revenue kept = visits kept × the average consultation fee on attended visits (all months)
     *
     * Null when month 1 or months 2–3 have no recorded outcomes yet.
     */
    public static function roi(array $months)
    {
        $baseline = $months[1] ?? null;
        $later = null;
        foreach ([2, 3] as $m) {
            if (isset($months[$m])) {
                $later = $later === null ? $months[$m] : self::add($later, $months[$m]);
            }
        }
        if ($baseline === null || $later === null) {
            return null;
        }
        $later = self::rates($later);
        if ($baseline['noShowRate'] === null || $later['noShowRate'] === null) {
            return null;
        }
        $feeVisits = 0;
        $feeSum = 0;
        foreach ($months as $m) {
            $feeVisits += $m['feeVisits'];
            $feeSum += $m['consultationFees'];
        }
        $averageFee = $feeVisits > 0 ? $feeSum / $feeVisits : null;
        $outcomes = $later['attended'] + $later['noShows'];
        $visitsKept = max(0, $baseline['noShowRate'] - $later['noShowRate']) * $outcomes;
        $revenueKept = $averageFee !== null ? $visitsKept * $averageFee : null;

        return [
            'baselineRate' => $baseline['noShowRate'],
            'laterRate' => $later['noShowRate'],
            'points' => 100 * ($later['noShowRate'] - $baseline['noShowRate']),
            'outcomes' => $outcomes,
            'visitsKept' => $visitsKept,
            'averageFee' => $averageFee,
            'revenueKept' => $revenueKept,
            'feesForfeited' => $later['feesForfeited'],
            'total' => ($revenueKept ?? 0) + $later['feesForfeited'],
        ];
    }

    /** The CSV file's text (UTF-8 with a byte-order mark, so spreadsheet programs read it right). */
    public static function csv(array $report)
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach (self::csvRows($report) as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $text = stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /** The CSV's file name, e.g. "pilot-report-avenue-clinic-2026-12-01.csv". */
    public static function csvName(Facility $facility)
    {
        return 'pilot-report-' . \yii\helpers\Inflector::slug($facility->name) . '-' . self::today() . '.csv';
    }

    /**
     * Rows for a CSV: one per consultant and month, then the facility total per month.
     *
     * @return array[] the first row is the header
     */
    public static function csvRows(array $report)
    {
        $header = ['Facility', 'Consultant', 'Pilot start', 'Month', 'From', 'To', 'Status', 'Bookings', 'Cancelled',
            'Attended', 'No-shows', 'Outcome not recorded', 'No-show rate %', 'Late cancellations', 'Late cancellations per 100',
            'Booked ahead', 'Walk-ins', 'Booked ahead %', 'Fees collected (Ksh)', 'Fees absorbed (Ksh)', 'Fees forfeited (Ksh)',
            'Fees credited (Ksh)', 'Fees refunded (Ksh)', 'Incentives earned (Ksh)', 'Consultation fees on attended visits (Ksh)'];
        $rows = [$header];
        $line = function ($name, $start, $month, array $f) use ($report) {
            return [$report['facility']->name, $name, $start, $month, $f['from'] ?? '', $f['to'] ?? '', $f['status'] ?? '',
                (int) $f['bookings'], (int) $f['cancelled'], (int) $f['attended'], (int) $f['noShows'], (int) $f['unmarked'],
                $f['noShowRate'] === null ? '' : round(100 * $f['noShowRate'], 1), (int) $f['lateCancels'],
                $f['lateCancelRate'] === null ? '' : round($f['lateCancelRate'], 1), (int) $f['ahead'], (int) $f['walkIns'],
                $f['aheadShare'] === null ? '' : round(100 * $f['aheadShare'], 1), (int) $f['feesCollected'], (int) $f['feesAbsorbed'],
                (int) $f['feesForfeited'], (int) $f['feesCredited'], (int) $f['feesRefunded'], (int) $f['incentives'], (int) $f['consultationFees']];
        };
        foreach ($report['consultants'] as $c) {
            foreach ($c['months'] as $month => $f) {
                $rows[] = $line($c['consultant']->names, $c['start'], $month, $f);
            }
        }
        foreach ($report['total'] as $month => $f) {
            $rows[] = $line('All consultants', '', $month, $f);
        }

        return $rows;
    }
}
