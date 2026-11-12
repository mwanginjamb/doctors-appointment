<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * An external engagement (F8 phase 3): weekly hours the consultant works somewhere that does not
 * use the app (e.g. Kenyatta National Hospital on Wednesday mornings). Nobody can book them then,
 * and calendars show "busy at <place>" instead of "away". It may not overlap their hours at
 * facilities in the app.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property string $place
 * @property int $day_of_week ISO: 1 = Monday
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property string|null $from_date Y-m-d
 * @property string|null $to_date Y-m-d
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Consultant $consultant
 */
class ConsultantExternalBlock extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%consultant_external_block}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'external_block'],
        ];
    }

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_DEFAULT][] = '!consultant_id';

        return $scenarios;
    }

    public function rules()
    {
        return [
            [['place', 'day_of_week', 'start_time', 'end_time'], 'required'],
            [['place'], 'trim'],
            [['place'], 'string', 'max' => 150],
            [['day_of_week'], 'integer', 'min' => 1, 'max' => 7],
            [['start_time', 'end_time'], 'filter', 'filter' => function ($value) {
                return preg_match('/^\d{2}:\d{2}$/', (string) $value) ? $value . ':00' : $value;
            }],
            [['start_time', 'end_time'], 'time', 'format' => 'php:H:i:s'],
            [['end_time'], 'compare', 'compareAttribute' => 'start_time', 'operator' => '>', 'type' => 'string', 'skipOnError' => true,
                'message' => Yii::t('app', 'It must end after it starts.')],
            [['from_date', 'to_date'], 'default', 'value' => null],
            [['from_date', 'to_date'], 'date', 'format' => 'php:Y-m-d'],
            [['to_date'], 'compare', 'compareAttribute' => 'from_date', 'operator' => '>=', 'type' => 'string', 'skipOnError' => true,
                'when' => function () {
                    return $this->from_date !== null;
                }, 'message' => Yii::t('app', 'The last day must be on or after the first.')],
            [['start_time'], 'validateNoOverlap', 'skipOnError' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'place' => Yii::t('app', 'Where'),
            'day_of_week' => Yii::t('app', 'Day'),
            'start_time' => Yii::t('app', 'From'),
            'end_time' => Yii::t('app', 'Until'),
            'from_date' => Yii::t('app', 'First day'),
            'to_date' => Yii::t('app', 'Last day'),
        ];
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    /** Whether it runs on $date (Y-m-d), by its dates; the weekday is checked by the caller. */
    public function runsOn($date)
    {
        return ($this->from_date === null || $date >= $this->from_date) && ($this->to_date === null || $date <= $this->to_date);
    }

    /** "Wed 08:00–13:00 at Kenyatta National Hospital (until 31 Jan)" */
    public function getLabel()
    {
        return substr(ConsultantWorkingHours::DAYS[(int) $this->day_of_week], 0, 3) . ' '
            . substr($this->start_time, 0, 5) . '–' . substr($this->end_time, 0, 5) . ' at ' . $this->place
            . ConsultantWorkingHours::rotationLabel(['from' => $this->from_date, 'to' => $this->to_date]);
    }

    /**
     * Not at the same time as the consultant's weekly hours in the app, nor another external block,
     * on that weekday (dates are not compared: keep them apart every week).
     */
    public function validateNoOverlap($attribute)
    {
        $consultant = $this->consultant;
        if ($consultant === null || $this->hasErrors('end_time')) {
            return;
        }
        foreach ($consultant->getBlocks()[(int) $this->day_of_week] ?? [] as [$start, $end, $facilityId]) {
            if ($this->start_time < $end && $this->end_time > $start) {
                $engagement = $consultant->getEngagement($facilityId);
                $this->addError($attribute, Yii::t('app', 'This overlaps your hours at {facility} ({hours}). Change those under Weekly hours first.', [
                    'facility' => $engagement->facility->name ?? '',
                    'hours' => substr($start, 0, 5) . '–' . substr($end, 0, 5),
                ]));
                return;
            }
        }
        foreach ($consultant->externalBlocks as $other) {
            if ((int) $other->id !== (int) $this->id && (int) $other->day_of_week === (int) $this->day_of_week
                && $this->start_time < $other->end_time && $this->end_time > $other->start_time) {
                $this->addError($attribute, Yii::t('app', 'This overlaps {other}.', ['other' => $other->getLabel()]));
                return;
            }
        }
    }
}
