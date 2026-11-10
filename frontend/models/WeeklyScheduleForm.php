<?php

namespace frontend\models;

use Yii;
use yii\base\Model;

/**
 * Edits a consultant's weekly opening hours on the Availability page.
 *
 * $hours is ISO day (1 = Monday) => list of ['start' => 'HH:MM', 'end' => 'HH:MM'].
 * A day with no ranges is closed; gaps between a day's ranges are breaks.
 */
class WeeklyScheduleForm extends Model
{
    const MAX_RANGES_PER_DAY = 6;

    /** @var array */
    public $hours = [];

    /** @var array ISO day => list of error messages for that day */
    public $dayErrors = [];

    /** @var Consultant */
    private $consultant;

    public function __construct(Consultant $consultant, $config = [])
    {
        $this->consultant = $consultant;
        foreach ($consultant->getWeek() as $day => $ranges) {
            foreach ($ranges as [$start, $end]) {
                $this->hours[$day][] = ['start' => substr($start, 0, 5), 'end' => substr($end, 0, 5)];
            }
        }
        parent::__construct($config);
    }

    public function getConsultant()
    {
        return $this->consultant;
    }

    /**
     * Takes the posted hours[day][i][start|end], dropping unknown days and fully empty rows.
     */
    public function load($data, $formName = null)
    {
        if (!isset($data['hours']) && !isset($data['submitted'])) {
            return false;
        }

        $this->hours = [];
        foreach ((array) ($data['hours'] ?? []) as $day => $ranges) {
            $day = (int) $day;
            if (!isset(ConsultantWorkingHours::DAYS[$day]) || !is_array($ranges)) {
                continue;
            }
            foreach ($ranges as $range) {
                $start = trim((string) ($range['start'] ?? ''));
                $end = trim((string) ($range['end'] ?? ''));
                if ($start !== '' || $end !== '') {
                    $this->hours[$day][] = ['start' => $start, 'end' => $end];
                }
            }
        }
        ksort($this->hours);

        return true;
    }

    public function validate($attributeNames = null, $clearErrors = true)
    {
        $this->dayErrors = [];

        foreach ($this->hours as $day => $ranges) {
            $name = ConsultantWorkingHours::DAYS[$day];
            if (count($ranges) > self::MAX_RANGES_PER_DAY) {
                $this->dayErrors[$day][] = Yii::t('app', '{day}: at most {n} time ranges.', ['day' => $name, 'n' => self::MAX_RANGES_PER_DAY]);
                continue;
            }

            foreach ($ranges as $range) {
                if (!$this->isTime($range['start']) || !$this->isTime($range['end'])) {
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: enter both times as HH:MM.', ['day' => $name]);
                    continue 2;
                }
                if ($range['start'] >= $range['end']) {
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: {start}–{end} must end after it starts.', ['day' => $name] + $range);
                    continue 2;
                }
            }

            // Sorted by start, each range must begin at or after the previous one ends
            usort($ranges, function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
            for ($i = 1; $i < count($ranges); $i++) {
                if ($ranges[$i]['start'] < $ranges[$i - 1]['end']) {
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: {a} and {b} overlap.', [
                        'day' => $name,
                        'a' => $ranges[$i - 1]['start'] . '–' . $ranges[$i - 1]['end'],
                        'b' => $ranges[$i]['start'] . '–' . $ranges[$i]['end'],
                    ]);
                    continue 2;
                }
            }
            $this->hours[$day] = $ranges;
        }

        return !$this->dayErrors;
    }

    /**
     * Replaces the consultant's week with the validated hours.
     */
    public function save()
    {
        if (!$this->validate()) {
            return false;
        }

        $week = [];
        foreach ($this->hours as $day => $ranges) {
            foreach ($ranges as $range) {
                $week[$day][] = [$range['start'] . ':00', $range['end'] . ':00'];
            }
        }
        ConsultantWorkingHours::replaceWeek($this->consultant->id, $week);
        unset($this->consultant->workingHours); // reload on next access

        return true;
    }

    /**
     * Upcoming active appointments that no longer fit the saved week. They are kept; the consultant decides.
     *
     * @return Appointments[]
     */
    public function findAppointmentsOutsideHours()
    {
        $upcoming = Appointments::find()
            ->where(['consultant_id' => $this->consultant->user_id])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->orderBy(['date' => SORT_ASC, 'time' => SORT_ASC])
            ->all();

        return array_values(array_filter($upcoming, function (Appointments $a) {
            return $a->isFuture() && !$this->consultant->isOpenFor($a->date, $a->time, $a->getDurationMinutes());
        }));
    }

    private function isTime($value)
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
