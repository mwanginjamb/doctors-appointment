<?php

namespace frontend\models;

use yii\base\BaseObject;

/**
 * Headline numbers for the admin dashboard and the front-desk board.
 *
 * Every figure is limited to $consultantUserIds when given (front desk: their assigned
 * consultants); null means the whole clinic (admins). "Active" means scheduled or confirmed.
 * Completed and no-show counts come with issue 6, when those statuses are recorded.
 */
class ClinicStats extends BaseObject
{
    /** @var int[]|null consultant user ids to count; null = every consultant */
    public $consultantUserIds;

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

    /** Appointments in scope, without AppointmentsQuery's default ordering. */
    private function scoped()
    {
        $query = Appointments::find()->orderBy(null);
        if ($this->consultantUserIds !== null) {
            $query->andWhere(['consultant_id' => $this->consultantUserIds ?: [0]]);
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
