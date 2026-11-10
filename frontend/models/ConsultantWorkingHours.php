<?php

namespace frontend\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * One opening-time range in a consultant's weekly schedule. A day can have several ranges
 * (a gap between them is a break); a day with none is closed.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property int $day_of_week ISO: 1 = Monday ... 7 = Sunday
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
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
            [['consultant_id', 'day_of_week', 'start_time', 'end_time'], 'required'],
            [['consultant_id'], 'integer'],
            [['day_of_week'], 'integer', 'min' => 1, 'max' => 7],
            [['start_time', 'end_time'], 'time', 'format' => 'php:H:i:s'],
        ];
    }

    /**
     * Replaces a consultant's whole week with $week (day => list of [start, end], times as HH:MM:SS).
     * Callers validate the ranges first; see WeeklyScheduleForm.
     *
     * @param int $consultantId consultant.id
     * @param array $week
     */
    public static function replaceWeek($consultantId, array $week)
    {
        $db = static::getDb();
        $transaction = $db->beginTransaction();
        try {
            static::deleteAll(['consultant_id' => $consultantId]);
            $now = time();
            $rows = [];
            foreach ($week as $day => $ranges) {
                foreach ($ranges as [$start, $end]) {
                    $rows[] = [$consultantId, $day, $start, $end, $now, $now];
                }
            }
            if ($rows) {
                $db->createCommand()->batchInsert(static::tableName(), ['consultant_id', 'day_of_week', 'start_time', 'end_time', 'created_at', 'updated_at'], $rows)->execute();
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
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
                return substr($r[0], 0, 5) . '–' . substr($r[1], 0, 5);
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
