<?php

namespace frontend\models;

use common\helpers\Phone;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\services\Waitlist;
use Yii;
use yii\base\Model;

/**
 * F4: joining a consultant's waiting list. A patient joins themselves; the consultant or their
 * front desk adds a patient they find by email or phone. With an appointment, the patient waits for
 * something earlier than it, at the same facility, and taking a time moves it.
 */
class WaitlistForm extends Model
{
    public $facility_id;
    public $from_date;
    public $to_date;
    public $part_of_day = 'any';
    /** @var string|null staff: the patient's email or phone */
    public $patient_lookup;

    /** @var Consultant */
    public $consultant;
    /** @var Appointments|null "something earlier than this appointment" */
    public $appointment;
    /** @var User|null the patient; set for a patient joining themselves, or found from the lookup */
    public $patient;
    /** @var bool staff adding a patient */
    public $byStaff = false;

    /** @var WaitlistEntry|null after save() */
    public $entry;

    public function init()
    {
        parent::init();
        if ($this->appointment) {
            $this->consultant = $this->appointment->consultant;
            $this->patient = $this->byStaff ? $this->appointment->patient : $this->patient;
            $this->facility_id = (int) $this->appointment->facility_id;
            $this->from_date = date('Y-m-d');
            $this->to_date = $this->appointment->date;
        }
        $this->from_date = $this->from_date ?: date('Y-m-d');
        $this->to_date = $this->to_date ?: date('Y-m-d', strtotime('+13 days'));
        $choices = $this->consultant ? $this->facilityChoices() : [];
        if ($this->facility_id === null && count($choices) === 1) {
            $this->facility_id = (int) key($choices);
        }
    }

    /**
     * Where the patient can wait, as id => name: the consultant's active facilities; patients only
     * where the consultant takes online bookings, front desk only at their own facility.
     */
    public function facilityChoices()
    {
        $own = $this->byStaff ? FrontDesk::facilityId() : null;
        $choices = [];
        foreach ($this->consultant->engagements as $engagement) {
            if ($own !== null && (int) $engagement->facility_id !== $own) {
                continue;
            }
            if (!$engagement->facility || !$engagement->facility->isActive()) {
                continue;
            }
            if (!$this->byStaff && !$engagement->isBookingOpen() && !$this->appointment) {
                continue;
            }
            $choices[(int) $engagement->facility_id] = $engagement->facility->name;
        }

        return $choices;
    }

    public static function partOfDayChoices()
    {
        return ['any' => 'Any time of day', 'morning' => 'Mornings (before 12:00)', 'afternoon' => 'Afternoons (from 12:00)'];
    }

    public function rules()
    {
        return [
            [['patient_lookup'], 'trim'],
            [['facility_id', 'from_date', 'to_date', 'part_of_day'], 'required'],
            [['patient_lookup'], 'required', 'when' => function () {
                return $this->byStaff && !$this->appointment;
            }, 'message' => Yii::t('app', "Enter the patient's email or phone number.")],
            [['facility_id'], 'integer'],
            [['facility_id'], 'in', 'range' => function () {
                return array_keys($this->facilityChoices());
            }, 'message' => Yii::t('app', 'Choose one of the facilities listed.')],
            [['part_of_day'], 'in', 'range' => array_keys(self::partOfDayChoices())],
            [['from_date', 'to_date'], 'date', 'format' => 'php:Y-m-d'],
            [['to_date'], 'validateRange', 'skipOnError' => true],
            [['patient_lookup'], 'validatePatient', 'skipOnEmpty' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'facility_id' => Yii::t('app', 'Facility'),
            'from_date' => Yii::t('app', 'Any day from'),
            'to_date' => Yii::t('app', 'to'),
            'part_of_day' => Yii::t('app', 'Time of day'),
            'patient_lookup' => Yii::t('app', "Patient's email or phone number"),
        ];
    }

    public function validateRange($attribute)
    {
        if ($this->appointment) {
            return; // up to the appointment
        }
        $today = date('Y-m-d');
        $maxAhead = (int) (Yii::$app->params['booking.maxDaysAhead'] ?? 90);
        $days = (strtotime($this->to_date) - strtotime($this->from_date)) / 86400 + 1;

        if ($this->from_date < $today) {
            $this->addError('from_date', Yii::t('app', 'The dates cannot start in the past.'));
        } elseif ($days < 1) {
            $this->addError($attribute, Yii::t('app', 'The dates must end on or after the day they start.'));
        } elseif ($days > Waitlist::maxDays()) {
            $this->addError($attribute, Yii::t('app', 'Wait for at most {n} days at a time.', ['n' => Waitlist::maxDays()]));
        } elseif ($maxAhead > 0 && $this->to_date > date('Y-m-d', strtotime("+{$maxAhead} days"))) {
            $this->addError($attribute, Yii::t('app', 'Appointments can be booked at most {n} days ahead.', ['n' => $maxAhead]));
        }
    }

    /**
     * Staff: the one patient with this email or phone number.
     */
    public function validatePatient($attribute)
    {
        $value = $this->$attribute;
        $query = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->andWhere(['or', ['role' => User::ACCOUNT_PATIENT], ['role' => null], ['role' => '']]);
        if (strpos($value, '@') !== false) {
            $query->andWhere(['email' => $value]);
        } elseif (($phone = Phone::normalize($value)) !== null) {
            $query->andWhere(['phone_key' => $phone]);
        } else {
            $this->addError($attribute, Yii::t('app', 'Enter an email address or a phone number such as 0712 345 678.'));
            return;
        }
        $matches = $query->limit(2)->all();
        if (!$matches) {
            $this->addError($attribute, Yii::t('app', 'No patient has this email or phone number. Register them first, from the calendar.'));
        } elseif (count($matches) > 1) {
            $this->addError($attribute, Yii::t('app', 'Several patients share this phone number. Use their email, or add them from one of their appointments.'));
        } else {
            $this->patient = $matches[0];
        }
    }

    /**
     * @return bool whether the patient is now on the list ($entry)
     */
    public function save()
    {
        if (!$this->validate()) {
            return false;
        }
        if ($this->patient === null) {
            $this->addError('patient_lookup', Yii::t('app', "Enter the patient's email or phone number."));
            return false;
        }
        if ($this->appointment && !$this->appointment->canBeCancelled()) {
            $this->addError('to_date', Yii::t('app', 'This appointment can no longer be moved.'));
            return false;
        }

        $waiting = WaitlistEntry::find()->where(['patient_id' => $this->patient->id, 'status' => WaitlistEntry::STATUS_WAITING]);
        $max = (int) Waitlist::param('maxEntriesPerPatient', 5);
        $isUpdate = (clone $waiting)->andWhere(['consultant_id' => $this->consultant->id])
            ->andWhere($this->appointment ? ['appointment_id' => $this->appointment->id] : ['facility_id' => $this->facility_id, 'appointment_id' => null])
            ->exists();
        if (!$isUpdate && $waiting->count() >= $max) {
            $this->addError('facility_id', Yii::t('app', 'You can be on at most {n} waiting lists at a time. Leave one first.', ['n' => $max]));
            return false;
        }

        [$dayStart, $dayEnd] = WaitlistEntry::PARTS_OF_DAY[$this->part_of_day];
        $this->entry = Waitlist::join([
            'patient_id' => $this->patient->id,
            'consultant_id' => $this->consultant->id,
            'facility_id' => (int) $this->facility_id,
            'from_date' => $this->appointment ? date('Y-m-d') : $this->from_date,
            'to_date' => $this->appointment ? $this->appointment->date : $this->to_date,
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'appointment_id' => $this->appointment->id ?? null,
        ], Yii::$app->user->isGuest ? $this->patient->id : Yii::$app->user->id);

        return true;
    }
}
