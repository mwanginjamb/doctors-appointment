<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A one-off session (F8 phase 3): a consultant sees patients at a facility on one date, outside
 * their weekly pattern, e.g. a Saturday outreach clinic. It is bookable like weekly hours, with the
 * session length and fee of the consultant's engagement there.
 *
 * Added by the consultant, by front desk for their consultants at their own facility, or by a
 * facility admin at theirs (permission manageConsultantSessions). It may not overlap the
 * consultant's other hours that day, wherever they are, nor fall on a public holiday or a day the
 * facility is closed. One with appointments booked in it cannot be removed.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property int $facility_id
 * @property string $date Y-m-d
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property string|null $note e.g. "Outreach clinic"
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Consultant $consultant
 * @property Facility $facility
 */
class ConsultantSession extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%consultant_session}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'consultant_session'],
        ];
    }

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        // Whose session it is comes from the page, never from the form
        $scenarios[self::SCENARIO_DEFAULT][] = '!consultant_id';

        return $scenarios;
    }

    public function rules()
    {
        return [
            [['facility_id', 'date', 'start_time', 'end_time'], 'required'],
            [['facility_id'], 'integer'],
            [['note'], 'trim'],
            [['note'], 'string', 'max' => 150],
            [['note'], 'default', 'value' => null],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['start_time', 'end_time'], 'filter', 'filter' => function ($value) {
                return preg_match('/^\d{2}:\d{2}$/', (string) $value) ? $value . ':00' : $value;
            }],
            [['start_time', 'end_time'], 'time', 'format' => 'php:H:i:s'],
            [['date'], 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '>=', 'type' => 'string',
                'message' => Yii::t('app', 'Choose today or a later day.')],
            [['end_time'], 'compare', 'compareAttribute' => 'start_time', 'operator' => '>', 'type' => 'string', 'skipOnError' => true,
                'message' => Yii::t('app', 'The session must end after it starts.')],
            [['facility_id'], 'validateFacility', 'skipOnError' => true],
            [['date'], 'validateDay', 'skipOnError' => true],
            [['start_time'], 'validateNoOverlap', 'skipOnError' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'facility_id' => Yii::t('app', 'Facility'),
            'date' => Yii::t('app', 'Date'),
            'start_time' => Yii::t('app', 'From'),
            'end_time' => Yii::t('app', 'Until'),
            'note' => Yii::t('app', 'Note (staff see it)'),
        ];
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    /** The consultant works at the facility, and it is taking bookings. */
    public function validateFacility($attribute)
    {
        $engagement = $this->consultant ? $this->consultant->getEngagement($this->facility_id) : null;
        if ($engagement === null || !$engagement->facility->isActive()) {
            $this->addError($attribute, Yii::t('app', 'Choose a facility where {doctor} works and that is taking bookings.', [
                'doctor' => $this->consultant->names ?? Yii::t('app', 'the consultant'),
            ]));
        }
    }

    /** Not a public holiday, and the facility is not closed that day. */
    public function validateDay($attribute)
    {
        if ($holiday = PublicHoliday::findOnDate($this->date)) {
            $this->addError($attribute, Yii::t('app', '{date} is a public holiday ({name}); the clinic is closed.', [
                'date' => date('l j M', strtotime($this->date)),
                'name' => $holiday->name,
            ]));
        } elseif ($this->facility_id && ($closure = FacilityClosure::findOn($this->facility_id, $this->date))) {
            $this->addError($attribute, Yii::t('app', '{facility} is closed on {date} ({reason}).', [
                'facility' => $closure->facility->name ?? '',
                'date' => date('l j M', strtotime($this->date)),
                'reason' => $closure->reason,
            ]));
        }
    }

    /**
     * The consultant is in one place at a time: the session may not overlap their hours that day
     * (weekly, other sessions) at any facility, nor their hours at places that do not use the app.
     */
    public function validateNoOverlap($attribute)
    {
        $consultant = $this->consultant;
        if ($consultant === null || $this->hasErrors('end_time')) {
            return;
        }
        unset($consultant->sessions);
        foreach ($consultant->blocksOn($this->date) as [$start, $end, $facilityId, $extra]) {
            if (!$this->isNewRecord && (int) ($extra['session'] ?? 0) === (int) $this->id) {
                continue;
            }
            if ($this->start_time < $end && $this->end_time > $start) {
                $engagement = $consultant->getEngagement($facilityId);
                $this->addError($attribute, Yii::t('app', 'This overlaps {doctor}\'s hours at {facility} that day ({hours}).', [
                    'doctor' => $consultant->names,
                    'facility' => $engagement->facility->name ?? '',
                    'hours' => substr($start, 0, 5) . '–' . substr($end, 0, 5),
                ]));
                return;
            }
        }
        foreach ($consultant->externalOn($this->date) as [$start, $end, $place]) {
            if ($this->start_time < $end && $this->end_time > $start) {
                $this->addError($attribute, Yii::t('app', '{doctor} is busy at {place} then ({hours}).', [
                    'doctor' => $consultant->names,
                    'place' => $place,
                    'hours' => substr($start, 0, 5) . '–' . substr($end, 0, 5),
                ]));
                return;
            }
        }
    }

    /**
     * Active appointments booked inside the session.
     *
     * @return \yii\db\ActiveQuery
     */
    public function bookedAppointments()
    {
        return Appointments::find()->orderBy(null)
            ->where(['consultant_id' => $this->consultant->user_id ?? 0, 'facility_id' => $this->facility_id, 'date' => $this->date])
            ->andWhere(['>=', 'time', $this->start_time])
            ->andWhere(['<', 'time', $this->end_time])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]]);
    }

    /** "Sat 6 Dec 2026, 09:00–13:00 at Kibera Outreach" */
    public function getLabel()
    {
        return date('D j M Y', strtotime($this->date)) . ', ' . substr($this->start_time, 0, 5) . '–' . substr($this->end_time, 0, 5)
            . ' at ' . ($this->facility->name ?? '');
    }

    /**
     * Tells the consultant that someone else added or removed one of their sessions, by email and
     * WhatsApp. Nothing is sent when they did it themselves.
     *
     * @param bool $removed
     * @return string[] channels queued
     */
    public function notifyConsultant($removed = false)
    {
        $consultant = $this->consultant;
        $by = Yii::$app->get('user', false) && !Yii::$app->user->isGuest ? Yii::$app->user->identity : null;
        if ($consultant === null || ($by !== null && (int) $by->id === (int) $consultant->user_id)) {
            return [];
        }
        $doctor = $consultant->names ?: 'Doctor';
        $who = $by ? ($by->full_name ?: $by->username) : 'The clinic';
        $subject = $removed ? 'A session was removed from your calendar' : 'A session was added to your calendar';
        $text = "Hello {$doctor},\n\n{$who} " . ($removed ? 'removed' : 'added') . " a session "
            . ($removed ? 'from' : 'to') . " your calendar: {$this->getLabel()}."
            . ($this->note ? "\nNote: {$this->note}" : '')
            . ($removed ? '' : "\n\nPatients can now be booked into it. If this is wrong, remove it on your Availability page.");

        $channels = [];
        if ($consultant->consultant_email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $consultant->consultant_email,
                'toName' => $doctor,
                'subject' => $subject,
                'text' => $text,
            ]));
            $channels[] = 'email';
        }
        if ($consultant->consultant_phone_number) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $consultant->consultant_phone_number, 'text' => $text]));
            $channels[] = 'whatsapp';
        }

        return $channels;
    }
}
