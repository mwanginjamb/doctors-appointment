<?php

namespace frontend\models;

use yii\base\BaseObject;

/**
 * Headline numbers for the admin dashboard and the front-desk board.
 *
 * Every figure is limited to $consultantUserIds when given (front desk: their assigned
 * consultants) and to $facilityId (front desk: their facility; admins: a filter); null means the
 * whole clinic (admins). "Active" means scheduled or confirmed.
 * Completed and no-show counts come with issue 6, when those statuses are recorded.
 */
class ClinicStats extends BaseObject
{
    /** @var int[]|null consultant user ids to count; null = every consultant */
    public $consultantUserIds;

    /** @var int|null only appointments at this facility (front desk's own, or an admin's filter); null = all */
    public $facilityId;

    /** @var int days counted back for the cancellation rate, today included */
    public $cancellationWindowDays = 30;

    /** @var string Y-m-d; "today" for every figure, overridable in tests */
    public $today;

    public function init()
    {
        parent::init();
        $this->today = $this->today ?: date('Y-m-d');
    }

    /** Active appointments today. */
    public function getToday()
    {
        return (int) $this->active()->andWhere(['date' => $this->today])->count();
    }

    /** Active appointments from today through the next 6 days. */
    public function getNextSevenDays()
    {
        return (int) $this->active()->andWhere(['between', 'date', $this->today, $this->day(6)])->count();
    }

    /**
     * Appointments dated in the last $cancellationWindowDays days (today included), and how many
     * of them were cancelled.
     *
     * @return array{total:int, cancelled:int, rate:float|null} rate in percent, null when there were none
     */
    public function getCancellations()
    {
        $range = ['between', 'date', $this->day(1 - $this->cancellationWindowDays), $this->today];
        $total = (int) $this->scoped()->andWhere($range)->count();
        $cancelled = (int) $this->scoped()->andWhere($range)->andWhere(['status' => Appointments::STATUS_CANCELLED])->count();

        return [
            'total' => $total,
            'cancelled' => $cancelled,
            'rate' => $total > 0 ? round($cancelled * 100 / $total, 1) : null,
        ];
    }

    /**
     * Outcomes of appointments dated in the last $cancellationWindowDays days (today included) that
     * have started: how many attended, how many were no-shows, and how many still need an outcome.
     *
     * @return array{attended:int, noShows:int, unmarked:int, noShowRate:float|null} rate in percent of
     *               recorded outcomes, null when none are recorded
     */
    public function getOutcomes()
    {
        $range = ['between', 'date', $this->day(1 - $this->cancellationWindowDays), $this->today];
        $attended = (int) $this->scoped()->andWhere($range)->andWhere(['status' => Appointments::STATUS_COMPLETED])->count();
        $noShows = (int) $this->scoped()->andWhere($range)->andWhere(['status' => Appointments::STATUS_NO_SHOW])->count();
        // Started = an earlier day, or today at a time that has passed (only when "today" is the real today)
        $started = $this->today === date('Y-m-d')
            ? ['or', ['<', 'date', $this->today], ['<=', 'time', date('H:i:s')]]
            : ['<', 'date', $this->today];
        $unmarked = (int) $this->active()->andWhere($range)->andWhere($started)->count();

        return [
            'attended' => $attended,
            'noShows' => $noShows,
            'unmarked' => $unmarked,
            'noShowRate' => $attended + $noShows > 0 ? round($noShows * 100 / ($attended + $noShows), 1) : null,
        ];
    }

    /**
     * Appointments booked (created) in the last 7 days, split by who booked them.
     *
     * @return array{total:int, online:int, staff:int} online = the patient themselves
     */
    public function getBookedLastSevenDays()
    {
        $since = strtotime($this->day(-6) . ' 00:00:00');
        $total = (int) $this->scoped()->andWhere(['>=', 'created_at', $since])->count();
        $online = (int) $this->scoped()->andWhere(['>=', 'created_at', $since])
            ->andWhere('[[created_by]] = [[patient_id]]')->count();

        return ['total' => $total, 'online' => $online, 'staff' => $total - $online];
    }

    /**
     * Active appointments per day for the next 7 days, today first. Days with none are included.
     *
     * @return array Y-m-d => count
     */
    public function getPerDay()
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[$this->day($i)] = 0;
        }
        $rows = $this->active()
            ->select(['date', 'n' => 'COUNT(*)'])
            ->andWhere(['between', 'date', $this->today, $this->day(6)])
            ->groupBy('date')
            ->createCommand()
            ->queryAll();
        foreach ($rows as $row) {
            $days[$row['date']] = (int) $row['n'];
        }

        return $days;
    }

    /**
     * The consultants with the most active appointments in the next 7 days.
     *
     * @return array list of ['consultant' => Consultant|null, 'count' => int], busiest first
     */
    public function getBusiest($limit = 5)
    {
        $rows = $this->active()
            ->select(['consultant_id', 'n' => 'COUNT(*)'])
            ->andWhere(['between', 'date', $this->today, $this->day(6)])
            ->groupBy('consultant_id')
            ->orderBy(['n' => SORT_DESC, 'consultant_id' => SORT_ASC])
            ->limit($limit)
            ->createCommand()
            ->queryAll();
        $consultants = Consultant::find()->where(['user_id' => array_column($rows, 'consultant_id')])->indexBy('user_id')->all();

        return array_map(function ($row) use ($consultants) {
            return ['consultant' => $consultants[$row['consultant_id']] ?? null, 'count' => (int) $row['n']];
        }, $rows);
    }

    /**
     * Per facility, for settling up with each one (F8 phase 2): appointments dated from $from to $to
     * (Y-m-d, inclusive), how they ended, and the fees of those attended.
     *
     * Sessions counts every appointment that was not cancelled. Fees due adds up the fee recorded on
     * each attended appointment (the fee in force when it was booked); attended appointments booked
     * with no fee set are counted in noFee.
     *
     * @return array list of ['facility' => Facility|null, 'sessions', 'attended', 'noShows',
     *               'cancelled', 'unmarked', 'fees' => float, 'noFee'], busiest first
     */
    public function getByFacility($from, $to)
    {
        $active = [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED];
        $rows = $this->scoped()
            ->select([
                'facility_id',
                'sessions' => 'SUM(CASE WHEN [[status]] IS NULL OR [[status]] <> :cancelled THEN 1 ELSE 0 END)',
                'attended' => 'SUM(CASE WHEN [[status]] = :completed THEN 1 ELSE 0 END)',
                'noShows' => 'SUM(CASE WHEN [[status]] = :noShow THEN 1 ELSE 0 END)',
                'cancelled' => 'SUM(CASE WHEN [[status]] = :cancelled THEN 1 ELSE 0 END)',
                'unmarked' => 'SUM(CASE WHEN ([[status]] IS NULL OR [[status]] IN (:scheduled, :confirmed)) AND [[date]] < :today THEN 1 ELSE 0 END)',
                'fees' => 'SUM(CASE WHEN [[status]] = :completed THEN COALESCE([[fee]], 0) ELSE 0 END)',
                'noFee' => 'SUM(CASE WHEN [[status]] = :completed AND [[fee]] IS NULL THEN 1 ELSE 0 END)',
            ])
            ->addParams([
                ':cancelled' => Appointments::STATUS_CANCELLED,
                ':completed' => Appointments::STATUS_COMPLETED,
                ':noShow' => Appointments::STATUS_NO_SHOW,
                ':scheduled' => $active[0],
                ':confirmed' => $active[1],
                ':today' => $this->today,
            ])
            ->andWhere(['between', 'date', $from, $to])
            ->groupBy('facility_id')
            ->orderBy(['sessions' => SORT_DESC, 'facility_id' => SORT_ASC])
            ->createCommand()
            ->queryAll();
        $facilities = Facility::find()->where(['id' => array_filter(array_column($rows, 'facility_id'))])->indexBy('id')->all();

        return array_map(function ($row) use ($facilities) {
            return [
                'facility' => $facilities[$row['facility_id']] ?? null,
                'sessions' => (int) $row['sessions'],
                'attended' => (int) $row['attended'],
                'noShows' => (int) $row['noShows'],
                'cancelled' => (int) $row['cancelled'],
                'unmarked' => (int) $row['unmarked'],
                'fees' => (float) $row['fees'],
                'noFee' => (int) $row['noFee'],
            ];
        }, $rows);
    }

    /** Appointments in scope, without AppointmentsQuery's default ordering. */
    private function scoped()
    {
        $query = Appointments::find()->orderBy(null);
        if ($this->consultantUserIds !== null) {
            $query->andWhere(['consultant_id' => $this->consultantUserIds ?: [0]]);
        }
        if ($this->facilityId !== null) {
            $query->andWhere(['facility_id' => $this->facilityId]);
        }

        return $query;
    }

    private function active()
    {
        return $this->scoped()->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]]);
    }

    private function day($offset)
    {
        return date('Y-m-d', strtotime("{$this->today} {$offset} days"));
    }
}
