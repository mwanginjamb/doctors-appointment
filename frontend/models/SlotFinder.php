<?php

namespace frontend\models;

use yii\base\BaseObject;

/**
 * Works out a consultant's free start times over a range of dates: inside their opening hours (and
 * an optional daily window), in the future, and clear of other appointments (each by its own
 * length), time off and public holidays. Slots step by the consultant's session length from each
 * opening time, so they line up with the calendar grid.
 *
 * Used by booking links and by patients rescheduling from their self-service link.
 */
class SlotFinder extends BaseObject
{
    /** @var Consultant */
    public $consultant;
    /** @var string Y-m-d */
    public $from;
    /** @var string Y-m-d */
    public $to;
    /** @var string|null H:i:s, optional daily window */
    public $dayStart;
    public $dayEnd;
    /** @var int|null appointment to ignore (the one being rescheduled) */
    public $excludeAppointmentId;
    /** @var int earliest start as a timestamp; defaults to now */
    public $notBefore;

    /**
     * @return array Y-m-d => list of 'H:i' start times (dates without free slots have an empty list)
     */
    public function find()
    {
        $dates = [];
        for ($ts = strtotime($this->from); $ts <= strtotime($this->to); $ts = strtotime('+1 day', $ts)) {
            $dates[] = date('Y-m-d', $ts);
        }
        $slots = array_fill_keys($dates, []);
        $consultant = $this->consultant;
        if (!$consultant || !$consultant->isTakingBookings() || !$dates) {
            return $slots;
        }

        $notBefore = $this->notBefore ?? time();
        $length = $consultant->getSessionMinutes() * 60;
        $holidays = array_map(function ($h) {
            return $h->date;
        }, PublicHoliday::between($this->from, $this->to));
        $blocked = array_map(function ($t) {
            return [strtotime($t->start_at), strtotime($t->end_at)];
        }, ConsultantTimeOff::overlapping($consultant->id, $this->from . ' 00:00:00', date('Y-m-d', strtotime($this->to . ' +1 day')) . ' 00:00:00'));
        $busy = Appointments::find()->orderBy(null)
            ->where(['consultant_id' => $consultant->user_id])
            ->andWhere(['between', 'date', $this->from, $this->to])
            ->andWhere(['or', ['status' => null], ['<>', 'status', Appointments::STATUS_CANCELLED]])
            ->andFilterWhere(['<>', 'id', $this->excludeAppointmentId])
            ->all();
        foreach ($busy as $appointment) {
            $start = strtotime($appointment->date . ' ' . $appointment->time);
            $blocked[] = [$start, $start + $appointment->getDurationMinutes() * 60];
        }

        foreach ($dates as $date) {
            if (in_array($date, $holidays, true)) {
                continue;
            }
            foreach ($consultant->getRangesFor($date) as [$open, $close]) {
                $from = strtotime($date . ' ' . $open);
                $to = strtotime($date . ' ' . $close);
                if ($this->dayStart) {
                    $from = max($from, strtotime($date . ' ' . $this->dayStart));
                }
                if ($this->dayEnd) {
                    $to = min($to, strtotime($date . ' ' . $this->dayEnd));
                }
                for ($start = strtotime($date . ' ' . $open); $start + $length <= $to; $start += $length) {
                    if ($start < $from || $start <= $notBefore) {
                        continue;
                    }
                    $end = $start + $length;
                    foreach ($blocked as [$bStart, $bEnd]) {
                        if ($start < $bEnd && $end > $bStart) {
                            continue 2;
                        }
                    }
                    $slots[$date][] = date('H:i', $start);
                }
            }
        }

        return $slots;
    }
}
