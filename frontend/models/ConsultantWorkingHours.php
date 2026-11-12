<?php

namespace frontend\models;

use common\components\Audit;
use common\models\AuditLog;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * One opening-time range in a consultant's weekly schedule, at one facility. A day can have several
 * ranges (a gap between them is a break, or travel between facilities); a day with none is closed.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property int $facility_id where the consultant sees patients during it
 * @property int $day_of_week ISO: 1 = Monday ... 7 = Sunday
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property string|null $weeks 'A' or 'B' for alternate weeks (Consultant::weekLetter); null = every week
 * @property string|null $from_date Y-m-d, first day these hours run; null = already
 * @property string|null $to_date Y-m-d, last day; null = open-ended
 * @property int|null $created_at
 * @property int|null $updated_at
 */
class ConsultantWorkingHours extends ActiveRecord
{
    const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /** The week a new consultant starts with: Mon-Sat 08:00-13:00 and 14:00-17:00 (lunch 13-14). */
    const DEFAULT_WEEK = [
        1 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        2 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        3 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        4 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        5 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        6 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
    ];

    public static function tableName()
    {
        return '{{%consultant_working_hours}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function rules()
    {
        return [
            [['consultant_id', 'facility_id', 'day_of_week', 'start_time', 'end_time'], 'required'],
            [['consultant_id', 'facility_id'], 'integer'],
            [['day_of_week'], 'integer', 'min' => 1, 'max' => 7],
            [['start_time', 'end_time'], 'time', 'format' => 'php:H:i:s'],
            [['weeks'], 'in', 'range' => ['A', 'B']],
            [['from_date', 'to_date'], 'date', 'format' => 'php:Y-m-d'],
        ];
    }

    /**
     * When these hours run (F8 phase 3): ['weeks' => 'A'|'B'|null, 'from' => Y-m-d|null,
     * 'to' => Y-m-d|null]. All null: every week, open-ended.
     */
    public function getRotation()
    {
        return ['weeks' => $this->weeks ?: null, 'from' => $this->from_date ?: null, 'to' => $this->to_date ?: null];
    }

    /**
     * " (week A)", " (until 31 Jan)", " (week B, from 1 Dec)", or '' for every week, open-ended.
     */
    public static function rotationLabel(?array $rotation)
    {
        $parts = [];
        if (!empty($rotation['weeks'])) {
            $parts[] = 'week ' . $rotation['weeks'];
        }
        if (!empty($rotation['from'])) {
            $parts[] = 'from ' . date('j M', strtotime($rotation['from']));
        }
        if (!empty($rotation['to'])) {
            $parts[] = 'until ' . date('j M', strtotime($rotation['to']));
        }

        return $parts ? ' (' . implode(', ', $parts) . ')' : '';
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    /**
     * Replaces a consultant's whole week with $week: day => list of [start, end, facility_id,
     * rotation], times as HH:MM:SS, rotation as getRotation() returns it (optional: every week).
     * A range without a facility is at the consultant's first engagement (made when there is
     * none). Callers validate the ranges first; see WeeklyScheduleForm.
     *
     * @param int $consultantId consultant.id
     * @param array $week
     */
    public static function replaceWeek($consultantId, array $week)
    {
        $db = static::getDb();
        $transaction = $db->beginTransaction();
        try {
            $before = static::blocksOf($consultantId);
            $defaultFacility = null;
            $now = time();
            $rows = [];
            foreach ($week as $day => $ranges) {
                foreach ($ranges as $range) {
                    $facilityId = $range[2] ?? null;
                    if ($facilityId === null) {
                        $defaultFacility = $defaultFacility ?? self::defaultFacilityId($consultantId);
                        $facilityId = $defaultFacility;
                    }
                    $rotation = $range[3] ?? [];
                    $rows[] = [$consultantId, (int) $facilityId, $day, $range[0], $range[1],
                        $rotation['weeks'] ?? null, $rotation['from'] ?? null, $rotation['to'] ?? null, $now, $now];
                }
            }
            static::deleteAll(['consultant_id' => $consultantId]);
            if ($rows) {
                $db->createCommand()->batchInsert(static::tableName(), ['consultant_id', 'facility_id', 'day_of_week', 'start_time', 'end_time', 'weeks', 'from_date', 'to_date', 'created_at', 'updated_at'], $rows)->execute();
            }
            // Saved in bulk, so the audit behaviour does not see it
            $old = static::describe($before);
            $new = static::describe(static::blocksOf($consultantId));
            if ($old !== $new) {
                Audit::log(AuditLog::ACTION_HOURS_CHANGED, 'consultant', $consultantId, ['hours' => [$old, $new]]);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * The facility of the consultant's first engagement, made from their profile when they have none.
     */
    private static function defaultFacilityId($consultantId)
    {
        $facilityId = ConsultantFacility::find()->select('facility_id')->where(['consultant_id' => $consultantId])->orderBy(['id' => SORT_ASC])->scalar();
        if ($facilityId) {
            return (int) $facilityId;
        }
        $consultant = Consultant::findOne($consultantId);

        return (int) ConsultantFacility::createDefault($consultant)->facility_id;
    }

    /**
     * A consultant's saved week as day => list of [start, end, facility_id].
     */
    public static function blocksOf($consultantId)
    {
        $week = [];
        foreach (static::find()->where(['consultant_id' => $consultantId])->orderBy(['day_of_week' => SORT_ASC, 'start_time' => SORT_ASC])->all() as $row) {
            $week[(int) $row->day_of_week][] = [$row->start_time, $row->end_time, (int) $row->facility_id, $row->getRotation()];
        }

        return $week;
    }

    /**
     * Summary of a week of [start, end, facility_id] blocks, for the audit log and pages: the plain
     * summary when all hours are at one facility, else one summary per facility
     * ("Nairobi Hospital: Mon 08:00–13:00; Avenue Clinic: Tue 14:00–18:00").
     */
    public static function describe(array $blocks)
    {
        $byFacility = static::splitByFacility($blocks);
        if (count($byFacility) <= 1) {
            return static::summarize($blocks);
        }
        $names = Facility::find()->select(['name', 'id'])->where(['id' => array_keys($byFacility)])->indexBy('id')->column();
        $parts = [];
        foreach ($byFacility as $facilityId => $week) {
            $parts[] = ($names[$facilityId] ?? '#' . $facilityId) . ': ' . static::summarize($week);
        }
        sort($parts);

        return implode(' | ', $parts);
    }

    /**
     * @return array facility_id => (day => list of [start, end, facility_id])
     */
    public static function splitByFacility(array $blocks)
    {
        $byFacility = [];
        foreach ($blocks as $day => $ranges) {
            foreach ($ranges as $range) {
                $byFacility[(int) ($range[2] ?? 0)][$day][] = $range;
            }
        }

        return $byFacility;
    }

    /**
     * "Mon–Sat 08:00–13:00, 14:00–17:00" style summary of a week (day => list of [start, end]).
     * Consecutive days with the same hours are grouped.
     */
    public static function summarize(array $week)
    {
        $groups = [];
        foreach (self::DAYS as $day => $name) {
            $ranges = $week[$day] ?? [];
            if (!$ranges) {
                continue;
            }
            $hours = implode(', ', array_map(function ($r) {
                return substr($r[0], 0, 5) . '–' . substr($r[1], 0, 5) . self::rotationLabel($r[3] ?? null);
            }, $ranges));

            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['hours'] === $hours && $groups[$last]['to'] === $day - 1) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'hours' => $hours];
            }
        }

        if (!$groups) {
            return null;
        }

        return implode('; ', array_map(function ($g) {
            $days = substr(self::DAYS[$g['from']], 0, 3);
            if ($g['to'] !== $g['from']) {
                $days .= '–' . substr(self::DAYS[$g['to']], 0, 3);
            }
            return $days . ' ' . $g['hours'];
        }, $groups));
    }
}
