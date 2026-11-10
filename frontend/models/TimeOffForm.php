<?php

namespace frontend\models;

use Yii;
use yii\base\Model;

/**
 * Adds a period a consultant is away. Without times it covers whole days, start date to end
 * date inclusive; times narrow it to part of a day (e.g. 14:00 on 3 Dec to 10:00 on 4 Dec).
 */
class TimeOffForm extends Model
{
    const MAX_DAYS = 366;

    public $start_date;
    public $start_time;
    public $end_date;
    public $end_time;
    public $reason;

    /** @var ConsultantTimeOff|null the saved record */
    public $timeOff;

    public function rules()
    {
        return [
            [['start_date', 'end_date'], 'required'],
            [['start_date', 'end_date'], 'date', 'format' => 'php:Y-m-d'],
            [['start_time', 'end_time'], 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'message' => Yii::t('app', 'Enter the time as HH:MM.')],
            [['reason'], 'trim'],
            [['reason'], 'string', 'max' => 255],
            [['end_date'], 'validatePeriod', 'skipOnError' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'start_date' => Yii::t('app', 'From'),
            'start_time' => Yii::t('app', 'From time (optional)'),
            'end_date' => Yii::t('app', 'To'),
            'end_time' => Yii::t('app', 'To time (optional)'),
            'reason' => Yii::t('app', 'Reason (only you see this)'),
        ];
    }

    public function getStartAt()
    {
        return $this->start_date . ' ' . ($this->start_time ?: '00:00') . ':00';
    }

    /** Exclusive end: without a time, the end date is included in full. */
    public function getEndAt()
    {
        return $this->end_time
            ? $this->end_date . ' ' . $this->end_time . ':00'
            : date('Y-m-d 00:00:00', strtotime($this->end_date . ' +1 day'));
    }

    public function validatePeriod($attribute)
    {
        $start = strtotime($this->getStartAt());
        $end = strtotime($this->getEndAt());

        if ($end <= $start) {
            $this->addError($attribute, Yii::t('app', 'Time off must end after it starts.'));
        } elseif ($end <= time()) {
            $this->addError($attribute, Yii::t('app', 'That period is already over.'));
        } elseif ($end - $start > self::MAX_DAYS * 86400) {
            $this->addError($attribute, Yii::t('app', 'Add time off of at most {n} days at a time.', ['n' => self::MAX_DAYS]));
        }
    }

    public function save(Consultant $consultant)
    {
        if (!$this->validate()) {
            return false;
        }

        $this->timeOff = new ConsultantTimeOff([
            'consultant_id' => $consultant->id,
            'start_at' => $this->getStartAt(),
            'end_at' => $this->getEndAt(),
            'reason' => $this->reason,
        ]);

        return $this->timeOff->save();
    }
}
