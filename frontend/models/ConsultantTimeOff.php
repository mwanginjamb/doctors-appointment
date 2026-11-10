<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A period a consultant is away. Nothing can be booked inside it.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property string $start_at Y-m-d H:i:s
 * @property string $end_at Y-m-d H:i:s, exclusive
 * @property string|null $reason
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ConsultantTimeOff extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%consultant_time_off}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'time_off'],
        ];
    }

    public function rules()
    {
        return [
            [['consultant_id', 'start_at', 'end_at'], 'required'],
            [['consultant_id'], 'integer'],
            [['start_at', 'end_at'], 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            [['reason'], 'trim'],
            [['reason'], 'string', 'max' => 255],
            [['reason'], 'default', 'value' => null],
        ];
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    /**
     * Time off of a consultant that overlaps [$start, $end) (Y-m-d H:i:s), earliest first.
     *
     * @return static[]
     */
    public static function overlapping($consultantId, $start, $end)
    {
        return static::find()
            ->where(['consultant_id' => $consultantId])
            ->andWhere(['<', 'start_at', $end])
            ->andWhere(['>', 'end_at', $start])
            ->orderBy(['start_at' => SORT_ASC])
            ->all();
    }

    /**
     * Whole days read as "14 Dec 2026" or "14–18 Dec 2026"; anything else shows the times.
     */
    public function getLabel()
    {
        $start = strtotime($this->start_at);
        $end = strtotime($this->end_at);
        $f = Yii::$app->formatter;

        if (date('H:i:s', $start) === '00:00:00' && date('H:i:s', $end) === '00:00:00') {
            $last = strtotime('-1 day', $end);
            return $last === $start
                ? $f->asDate($start, 'php:j M Y')
                : $f->asDate($start, 'php:j M Y') . ' – ' . $f->asDate($last, 'php:j M Y');
        }

        return $f->asDatetime($start, 'php:j M Y H:i') . ' – ' . $f->asDatetime($end, date('Y-m-d', $start) === date('Y-m-d', $end) ? 'php:H:i' : 'php:j M Y H:i');
    }

    /**
     * Upcoming active appointments booked with this consultant during this time off.
     *
     * @return Appointments[]
     */
    public function findClashingAppointments()
    {
        $consultant = $this->consultant;
        if (!$consultant) {
            return [];
        }

        $candidates = Appointments::find()
            ->where(['consultant_id' => $consultant->user_id])
            ->andWhere(['between', 'date', date('Y-m-d', strtotime($this->start_at)), date('Y-m-d', strtotime($this->end_at))])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->with('patient')
            ->orderBy(['date' => SORT_ASC, 'time' => SORT_ASC])
            ->all();

        $from = strtotime($this->start_at);
        $to = strtotime($this->end_at);

        return array_values(array_filter($candidates, function (Appointments $a) use ($from, $to) {
            $start = strtotime($a->date . ' ' . $a->time);
            $end = $start + $a->getDurationMinutes() * 60;
            return $a->isFuture() && $start < $to && $end > $from;
        }));
    }
}
