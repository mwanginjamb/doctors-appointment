<?php

namespace frontend\models;

use Yii;
use yii\base\Model;

/**
 * Edits a consultant's weekly opening hours on the Availability page.
 *
 * $hours is ISO day (1 = Monday) => list of ['start' => 'HH:MM', 'end' => 'HH:MM', 'facility' => id,
 * 'weeks' => 'A'|'B'|null, 'from' => Y-m-d|'', 'to' => Y-m-d|'']. A day with no ranges is closed;
 * gaps between a day's ranges are breaks. Each range is at one of the consultant's facilities; with
 * only one, ranges posted without a facility are there.
 *
 * F8 phase 3: a range can run every week or only in week A or week B of an alternate-week rotation
 * (counted from $rotationWeekA, the Monday of a week A), and between dates. Ranges may not overlap
 * when they can run on the same day, even at different facilities: the consultant is in one place
 * at a time. They may not overlap the consultant's external engagements or upcoming one-off
 * sessions either.
 */
class WeeklyScheduleForm extends Model
{
    const MAX_RANGES_PER_DAY = 6;

    /** @var array */
    public $hours = [];

    /** @var string|null Y-m-d, a day in a week A (saved as that week's Monday) */
    public $rotationWeekA;

    /** @var array ISO day => list of error messages for that day; 0 for the whole form */
    public $dayErrors = [];

    /** @var Consultant */
    private $consultant;

    public function __construct(Consultant $consultant, $config = [])
    {
        $this->consultant = $consultant;
        $this->rotationWeekA = $consultant->rotation_week_a;
        foreach ($consultant->getBlocks() as $day => $ranges) {
            foreach ($ranges as [$start, $end, $facilityId, $rotation]) {
                $this->hours[$day][] = [
                    'start' => substr($start, 0, 5), 'end' => substr($end, 0, 5), 'facility' => $facilityId,
                    'weeks' => $rotation['weeks'] ?? null, 'from' => (string) ($rotation['from'] ?? ''), 'to' => (string) ($rotation['to'] ?? ''),
                ];
            }
        }
        parent::__construct($config);
    }

    public function getConsultant()
    {
        return $this->consultant;
    }

    /**
     * Takes the posted hours[day][i][start|end|facility|weeks|from|to], dropping unknown days and
     * fully empty rows, and rotation_week_a.
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
                $facility = ctype_digit((string) ($range['facility'] ?? '')) ? (int) $range['facility'] : null;
                $weeks = in_array($range['weeks'] ?? null, ['A', 'B'], true) ? $range['weeks'] : null;
                if ($start !== '' || $end !== '') {
                    $this->hours[$day][] = [
                        'start' => $start, 'end' => $end, 'facility' => $facility, 'weeks' => $weeks,
                        'from' => trim((string) ($range['from'] ?? '')), 'to' => trim((string) ($range['to'] ?? '')),
                    ];
                }
            }
        }
        ksort($this->hours);
        if (array_key_exists('rotation_week_a', $data)) {
            $this->rotationWeekA = trim((string) $data['rotation_week_a']) ?: null;
        }

        return true;
    }

    public function validate($attributeNames = null, $clearErrors = true)
    {
        $this->dayErrors = [];
        $engaged = array_map(function ($e) {
            return (int) $e->facility_id;
        }, $this->consultant->engagements);

        $usesWeeks = false;
        foreach ($this->hours as $ranges) {
            foreach ($ranges as $range) {
                $usesWeeks = $usesWeeks || !empty($range['weeks']);
            }
        }
        if ($this->rotationWeekA !== null && !$this->isDate($this->rotationWeekA)) {
            $this->dayErrors[0][] = Yii::t('app', 'Week A: choose a date.');
        } elseif ($usesWeeks && $this->rotationWeekA === null) {
            $this->dayErrors[0][] = Yii::t('app', 'Some hours run only in week A or week B: choose a date in a week A.');
        }

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
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: {start}–{end} must end after it starts.', ['day' => $name, 'start' => $range['start'], 'end' => $range['end']]);
                    continue 2;
                }
                foreach (['from', 'to'] as $edge) {
                    if (($range[$edge] ?? '') !== '' && !$this->isDate($range[$edge])) {
                        $this->dayErrors[$day][] = Yii::t('app', '{day}: {start}–{end}: enter its dates as dates.', ['day' => $name, 'start' => $range['start'], 'end' => $range['end']]);
                        continue 3;
                    }
                }
                if (($range['from'] ?? '') !== '' && ($range['to'] ?? '') !== '' && $range['to'] < $range['from']) {
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: {start}–{end}: the last day must be on or after the first.', ['day' => $name, 'start' => $range['start'], 'end' => $range['end']]);
                    continue 2;
                }
            }

            // Each range is at one of the consultant's facilities; with only one, it is there
            foreach ($ranges as $i => $range) {
                if (($range['facility'] ?? null) === null && count($engaged) === 1) {
                    $ranges[$i]['facility'] = $engaged[0];
                } elseif (!in_array($range['facility'] ?? null, $engaged, true)) {
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: choose where you are for {start}–{end}.', ['day' => $name, 'start' => $range['start'], 'end' => $range['end']]);
                    continue 2;
                }
            }

            // Ranges that can run on the same day may not overlap
            usort($ranges, function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
            foreach ($ranges as $i => $a) {
                foreach (array_slice($ranges, $i + 1) as $b) {
                    if ($b['start'] < $a['end'] && $a['start'] < $b['end'] && self::canMeet($a, $b)) {
                        $this->dayErrors[$day][] = Yii::t('app', '{day}: {a} and {b} overlap.', [
                            'day' => $name,
                            'a' => $a['start'] . '–' . $a['end'],
                            'b' => $b['start'] . '–' . $b['end'],
                        ]);
                        continue 3;
                    }
                }
            }

            // ... nor the consultant's hours at places that do not use the app
            foreach ($this->consultant->externalBlocks as $external) {
                if ((int) $external->day_of_week !== $day) {
                    continue;
                }
                foreach ($ranges as $range) {
                    if ($range['start'] . ':00' < $external->end_time && $range['end'] . ':00' > $external->start_time) {
                        $this->dayErrors[$day][] = Yii::t('app', '{day}: {hours} overlaps {external}.', [
                            'day' => $name,
                            'hours' => $range['start'] . '–' . $range['end'],
                            'external' => $external->getLabel(),
                        ]);
                        continue 3;
                    }
                }
            }
            $this->hours[$day] = $ranges;
        }

        if (!$this->dayErrors) {
            $this->checkSessions();
        }

        return !$this->dayErrors;
    }

    /**
     * Upcoming one-off sessions stay where they are: the new week may not overlap them.
     */
    private function checkSessions()
    {
        $consultant = $this->consultant;
        $anchorBackup = $consultant->rotation_week_a;
        $consultant->rotation_week_a = $this->rotationWeekA;
        foreach ($consultant->sessions as $session) {
            if ($session->date < date('Y-m-d')) {
                continue;
            }
            $day = (int) date('N', strtotime($session->date));
            foreach ($this->hours[$day] ?? [] as $range) {
                $block = [$range['start'] . ':00', $range['end'] . ':00', $range['facility'], ['weeks' => $range['weeks'], 'from' => $range['from'] ?: null, 'to' => $range['to'] ?: null]];
                if ($consultant->blockRunsOn($block, $session->date) && $block[0] < $session->end_time && $block[1] > $session->start_time) {
                    $this->dayErrors[$day][] = Yii::t('app', '{day}: {hours} overlaps your session on {session}. Move or remove the session first.', [
                        'day' => ConsultantWorkingHours::DAYS[$day],
                        'hours' => $range['start'] . '–' . $range['end'],
                        'session' => $session->getLabel(),
                    ]);
                    break 2;
                }
            }
        }
        $consultant->rotation_week_a = $anchorBackup;
    }

    /**
     * Whether two ranges on the same weekday can run on the same date: their weeks of the rotation
     * and their dates allow it.
     */
    private static function canMeet(array $a, array $b)
    {
        if (!empty($a['weeks']) && !empty($b['weeks']) && $a['weeks'] !== $b['weeks']) {
            return false;
        }
        $aFrom = ($a['from'] ?? '') ?: '0000-00-00';
        $aTo = ($a['to'] ?? '') ?: '9999-12-31';
        $bFrom = ($b['from'] ?? '') ?: '0000-00-00';
        $bTo = ($b['to'] ?? '') ?: '9999-12-31';

        return $aFrom <= $bTo && $bFrom <= $aTo;
    }

    /**
     * Replaces the consultant's week with the validated hours, and saves week A's Monday.
     */
    public function save()
    {
        if (!$this->validate()) {
            return false;
        }

        $week = [];
        foreach ($this->hours as $day => $ranges) {
            foreach ($ranges as $range) {
                $week[$day][] = [$range['start'] . ':00', $range['end'] . ':00', $range['facility'], [
                    'weeks' => $range['weeks'] ?? null,
                    'from' => ($range['from'] ?? '') ?: null,
                    'to' => ($range['to'] ?? '') ?: null,
                ]];
            }
        }
        $anchor = $this->rotationWeekA ? date('Y-m-d', strtotime('monday this week', strtotime($this->rotationWeekA))) : null;
        if ($anchor !== $this->consultant->rotation_week_a) {
            $old = $this->consultant->rotation_week_a;
            $this->consultant->updateAttributes(['rotation_week_a' => $anchor]);
            \common\components\Audit::log(\common\models\AuditLog::ACTION_UPDATED, 'consultant', $this->consultant->id, ['rotation_week_a' => [$old, $anchor]]);
        }
        ConsultantWorkingHours::replaceWeek($this->consultant->id, $week);
        unset($this->consultant->workingHours, $this->consultant->engagements); // reload on next access

        return true;
    }

    /**
     * Upcoming active appointments that no longer fit the saved week at their facility. They are
     * kept; the consultant decides.
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
            return $a->isFuture() && !$this->consultant->isOpenFor($a->date, $a->time, $a->getDurationMinutes(), $a->facility_id);
        }));
    }

    private function isTime($value)
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    private function isDate($value)
    {
        $d = \DateTime::createFromFormat('!Y-m-d', (string) $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
