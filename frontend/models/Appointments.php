<?php

namespace frontend\models;

use common\components\RbacHelper;
use frontend\models\User;
use frontend\services\NotificationService;
use frontend\models\UserProfile;
use frontend\models\Consultant;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "appointments".
 *
 * @property int $id
 * @property string|null $date
 * @property string|null $time
 * @property int|null $patient_id
 * @property int|null $speciality_id
 * @property int|null $service_id
 * @property int|null $provider_id
 * @property string|null $location
 * @property int|null $recurring_appointment
 * @property int|null $walk_in_appointment
 * @property string|null $symptoms_brief
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $consultant_id
 * @property int|null $reminder_5hrs_sent
 * @property int|null $reminder_2hrs_sent
 * @property string|null $status (scheduled, completed, cancelled, no_show, confirmed)
 * @property string|null $cancellation_note why it was cancelled; set only by cancel()
 * @property int|null $patient_age
 */
class Appointments extends \yii\db\ActiveRecord
{
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_NO_SHOW = 'no_show';
    const STATUS_CONFIRMED = 'confirmed';

    /**
     * Attributes that are set by the system or derived from the logged-in user,
     * never mass-assigned from request data.
     */
    const PROTECTED_ATTRIBUTES = [
        'patient_id',
        'consultant_id',
        'status',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'reminder_5hrs_sent',
        'reminder_2hrs_sent',
        'cancellation_note',
    ];

    public $patient_age;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'appointments';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['date', 'time', 'patient_id', 'speciality_id', 'service_id', 'provider_id', 'location', 'recurring_appointment', 'walk_in_appointment', 'symptoms_brief', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id'], 'default', 'value' => null],
            [['date', 'time'], 'safe'],
            [['patient_id', 'speciality_id', 'service_id', 'provider_id', 'recurring_appointment', 'walk_in_appointment', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id', 'reminder_5hrs_sent', 'reminder_2hrs_sent'], 'integer'],
            [['location', 'symptoms_brief'], 'string'],
            [['status'], 'string', 'max' => 20],
            [['cancellation_note'], 'string', 'max' => 500],
            [['status'], 'in', 'range' => array_keys(self::getStatusList())],
            [['status'], 'default', 'value' => self::STATUS_SCHEDULED],
            [['date', 'time', 'patient_id', 'consultant_id'], 'required'],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['consultant_id'], 'exist', 'skipOnError' => true, 'targetClass' => Consultant::class, 'targetAttribute' => ['consultant_id' => 'user_id']],
            // Only checked when the patient is set or changed, so older rows stay editable
            [['patient_id'], 'validatePatient', 'when' => function ($model) {
                return $model->isNewRecord || $model->isAttributeChanged('patient_id', false);
            }],
            [['time'], 'validateSlot', 'when' => function ($model) {
                return $model->isNewRecord
                    || $model->isAttributeChanged('date', false)
                    || $model->isAttributeChanged('time', false)
                    || $model->isAttributeChanged('consultant_id', false);
            }],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        // '!' keeps the attribute validated but excludes it from load()
        $scenarios[self::SCENARIO_DEFAULT] = array_map(function ($attribute) {
            return in_array($attribute, self::PROTECTED_ATTRIBUTES, true) ? '!' . $attribute : $attribute;
        }, $scenarios[self::SCENARIO_DEFAULT]);

        return $scenarios;
    }

    /**
     * The patient must be a registered patient account, and never the consultant being booked.
     * Consultants book on a patient's behalf, so the patient id can come from someone else.
     */
    public function validatePatient($attribute, $params)
    {
        if ((int) $this->patient_id === (int) $this->consultant_id) {
            $this->addError($attribute, Yii::t('app', 'A consultant cannot book an appointment with themselves. Choose a patient.'));
            return;
        }

        // false when no such user; a null/empty role is a legacy patient account
        $role = User::find()->select('role')->where(['id' => $this->patient_id])->scalar();
        if ($role === false || RbacHelper::nativeRoleFor($role) !== RbacHelper::ROLE_PATIENT) {
            $this->addError($attribute, Yii::t('app', 'Choose a registered patient.'));
        }
    }

    /**
     * Ensures the requested slot is in the future, inside the consultant's
     * working hours and does not overlap another active appointment.
     */
    public function validateSlot($attribute, $params)
    {
        if ($this->hasErrors('date') || $this->hasErrors('consultant_id')) {
            return;
        }

        // A cancelled appointment stays as history; moving it would bring it back with reminders
        if (!$this->isNewRecord && $this->getOldAttribute('status') === self::STATUS_CANCELLED) {
            $this->addError($attribute, Yii::t('app', 'This appointment was cancelled and cannot be rescheduled. Book a new one instead.'));
            return;
        }

        $startTs = strtotime($this->date . ' ' . $this->time);
        if ($startTs === false) {
            $this->addError($attribute, Yii::t('app', 'Invalid appointment time.'));
            return;
        }

        $duration = $this->getDurationMinutes();
        $endTs = $startTs + $duration * 60;
        $this->time = date('H:i:s', $startTs);

        if ($startTs <= time()) {
            $this->addError($attribute, Yii::t('app', 'Appointments must be booked in the future.'));
            return;
        }

        // Booking window. A consultant booking a walk-in into their own calendar skips the notice.
        $notice = (int) (Yii::$app->params['booking.minNoticeMinutes'] ?? 0);
        if ($notice > 0 && $startTs < time() + $notice * 60 && !$this->isBookedByTheConsultant()) {
            $this->addError($attribute, Yii::t('app', 'Appointments must be booked at least {notice} in advance.', [
                'notice' => $notice % 60 === 0 ? ($notice === 60 ? '1 hour' : ($notice / 60) . ' hours') : $notice . ' minutes',
            ]));
            return;
        }
        $maxDays = (int) (Yii::$app->params['booking.maxDaysAhead'] ?? 0);
        if ($maxDays > 0 && $this->date > date('Y-m-d', strtotime("+{$maxDays} days"))) {
            $this->addError($attribute, Yii::t('app', 'Appointments can be booked at most {n} days ahead.', ['n' => $maxDays]));
            return;
        }

        $consultant = $this->consultant;
        $doctor = $consultant->names ?? Yii::t('app', 'The doctor');

        // The clinic is closed on public holidays, for every consultant
        if ($holiday = PublicHoliday::findOnDate($this->date)) {
            $this->addError($attribute, Yii::t('app', '{date} is a public holiday ({name}); the clinic is closed.', [
                'date' => date('l j M', $startTs),
                'name' => $holiday->name,
            ]));
            return;
        }

        // The consultant's time off. Its reason is private, so patients only see the dates.
        if ($consultant && ($away = ConsultantTimeOff::overlapping($consultant->id, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)))) {
            $this->addError($attribute, Yii::t('app', '{doctor} is away {period}. Please choose another time.', [
                'doctor' => $doctor,
                'period' => $away[0]->getLabel(),
            ]));
            return;
        }

        // The whole session must fit inside one of the consultant's opening ranges that day
        $ranges = $consultant ? $consultant->getRangesFor($this->date) : [];
        if (!$ranges) {
            $this->addError($attribute, Yii::t('app', "{doctor} doesn't see patients on {day}s.", [
                'doctor' => $doctor,
                'day' => date('l', $startTs),
            ]));
            return;
        }
        if (!$consultant->isOpenFor($this->date, $this->time, $duration)) {
            $this->addError($attribute, Yii::t('app', "That time is outside {doctor}'s hours on {day}: {hours}. A {minutes}-minute session must fit within them.", [
                'doctor' => $doctor,
                'day' => date('l', $startTs),
                'hours' => implode(', ', array_map(function ($r) {
                    return substr($r[0], 0, 5) . '–' . substr($r[1], 0, 5);
                }, $ranges)),
                'minutes' => $duration,
            ]));
            return;
        }

        // Two slots overlap when each one starts before the other ends.
        // Existing appointments are assumed to share this consultant's session length.
        $conflict = self::find()
            ->where(['date' => $this->date, 'consultant_id' => $this->consultant_id])
            ->andWhere(['or', ['status' => null], ['<>', 'status', self::STATUS_CANCELLED]])
            ->andWhere(['<', 'time', date('H:i:s', $endTs)])
            ->andWhere(new \yii\db\Expression('ADDTIME([[time]], SEC_TO_TIME(:duration)) > :start', [
                ':duration' => $duration * 60,
                ':start' => $this->time,
            ]))
            ->andFilterWhere(['<>', 'id', $this->id])
            ->exists();

        if ($conflict) {
            $this->addError($attribute, Yii::t('app', 'The doctor is not available at this time.'));
        }
    }

    /**
     * Whether the logged-in user is the consultant this appointment is booked with
     * (front-desk style booking). False in console runs, which have no user.
     */
    private function isBookedByTheConsultant()
    {
        $user = Yii::$app->get('user', false);

        return $user !== null && !$user->isGuest && (int) $user->id === (int) $this->consultant_id;
    }

    /**
     * Session length in minutes: the consultant's setting, otherwise the DURATION env value (seconds).
     */
    public function getDurationMinutes()
    {
        $consultant = $this->consultant;
        if ($consultant && $consultant->appointment_session_duration > 0) {
            return (int) $consultant->appointment_session_duration;
        }

        return (int) (env('DURATION', 30 * 60) / 60);
    }

    /**
     * Saves the appointment while holding a row lock on the consultant, so two
     * concurrent requests cannot both pass the overlap check for the same slot.
     */
    public function saveBooking()
    {
        $db = static::getDb();
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand('SELECT [[id]] FROM {{%consultant}} WHERE [[user_id]] = :uid FOR UPDATE', [
                ':uid' => $this->consultant_id,
            ])->queryScalar();

            if ($this->save()) {
                $transaction->commit();
                return true;
            }
            $transaction->rollBack();
            return false;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Only upcoming appointments that are still on (scheduled or confirmed) can be cancelled.
     * Past ones are left for completed / no-show.
     */
    public function canBeCancelled()
    {
        $active = $this->status === null || in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED], true);

        return $active && $this->isFuture();
    }

    /**
     * Cancels the appointment and keeps the record. afterSave() cancels its pending reminders,
     * BlameableBehavior records who cancelled it (updated_by), and the slot becomes free.
     *
     * @param string|null $note optional reason, up to 500 characters
     * @return bool false when the appointment cannot be cancelled or the note is invalid (see getErrors())
     */
    public function cancel($note = null)
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $note = trim((string) $note);
        $this->cancellation_note = $note === '' ? null : $note;
        if (!$this->validate(['cancellation_note'])) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        return $this->save(false);
    }

    /**
     * Who booked the appointment: the patient online, the consultant, or staff on their behalf.
     */
    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    /**
     * Who last changed the appointment; for a cancelled one, who cancelled it.
     */
    public function getUpdater()
    {
        return $this->hasOne(User::class, ['id' => 'updated_by']);
    }

    /**
     * Whether the given user is the patient, consultant or creator of this appointment.
     */
    public function isParticipant($userId)
    {
        if (!$userId) {
            return false;
        }

        return in_array((int) $userId, [(int) $this->patient_id, (int) $this->consultant_id, (int) $this->created_by], true);
    }

    public function isConsultant($userId)
    {
        return $userId && (int) $userId === (int) $this->consultant_id;
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'date' => Yii::t('app', 'Date'),
            'time' => Yii::t('app', 'Time'),
            'patient_id' => Yii::t('app', 'Patient'),
            'speciality_id' => Yii::t('app', 'Speciality ID'),
            'service_id' => Yii::t('app', 'Service ID'),
            'provider_id' => Yii::t('app', 'Provider ID'),
            'location' => Yii::t('app', 'Location'),
            'recurring_appointment' => Yii::t('app', 'Recurring Appointment'),
            'walk_in_appointment' => Yii::t('app', 'Walk In Appointment'),
            'symptoms_brief' => Yii::t('app', 'Symptoms Brief'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_by' => Yii::t('app', 'Created By'),
            'updated_by' => Yii::t('app', 'Updated By'),
            'consultant_id' => Yii::t('app', 'Consultant ID'),
            'status' => Yii::t('app', 'Status'),
            'cancellation_note' => Yii::t('app', 'Cancellation note'),
        ];
    }

    /**
     * Handle notification scheduling after insert
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert) {
            // Schedule notifications for new appointment
            $this->scheduleNotifications();
            // Send immediate confirmation notification
            NotificationService::sendImmediateConfirmation($this);
        } else {
            // Handle updates
            $this->handleAppointmentUpdate($changedAttributes);
            // Schedule notifications if date/time changed
            if (isset($changedAttributes['date']) || isset($changedAttributes['time'])) {
                // Send reschedule notification
                NotificationService::sendRescheduleNotification($this);
            }
        }
    }

    /**
     * Handle notification cancellation after delete
     */
    public function afterDelete()
    {
        parent::afterDelete();
        NotificationService::cancelNotificationsForAppointment($this->id);
    }

    /**
     * Handle appointment updates
     */
    private function handleAppointmentUpdate($changedAttributes)
    {
        $rescheduleNeeded = false;
        $cancelNeeded = false;
        $confirmNeeded = false;

        // Check if date or time changed
        if (isset($changedAttributes['date']) || isset($changedAttributes['time'])) {
            $rescheduleNeeded = true;
        }

        // Check if status changed to cancelled
        if (isset($changedAttributes['status']) && $this->status === self::STATUS_CANCELLED) {
            $cancelNeeded = true;
        }

        // check if status changed to confirmed
        if (isset($changedAttributes['status']) && $this->status === self::STATUS_CONFIRMED) {
            $confirmNeeded = true;
        }

        // Check if patient or consultant changed
        if (isset($changedAttributes['patient_id']) || isset($changedAttributes['consultant_id'])) {
            $rescheduleNeeded = true;
        }

        if ($cancelNeeded) {
            NotificationService::cancelNotificationsForAppointment($this->id);
        } elseif ($rescheduleNeeded) {
            NotificationService::rescheduleNotificationsForAppointment($this);
        } elseif ($confirmNeeded) {
            NotificationService::sendAppointmentConfirmation($this);
        }
    }

    /**
     * Schedule notifications for this appointment
     */
    public function scheduleNotifications()
    {
        try {
            NotificationService::scheduleNotificationsForAppointment($this);
        } catch (\Exception $e) {
            Yii::error('Failed to schedule notifications for appointment ' . $this->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Cancel notifications for this appointment
     */
    public function cancelNotifications()
    {
        NotificationService::cancelNotificationsForAppointment($this->id);
    }

    /**
     * Check if appointment is in the future
     */
    public function isFuture()
    {
        $appointmentDateTime = new \DateTime($this->date . ' ' . $this->time);
        return $appointmentDateTime > new \DateTime();
    }

    /**
     * Check if appointment is past
     */
    public function isPast()
    {
        return !$this->isFuture();
    }

    /**
     * Get appointment date and time as DateTime object
     */
    public function getDateTime()
    {
        return new \DateTime($this->date . ' ' . $this->time);
    }

    /**
     * Get formatted appointment date and time
     */
    public function getFormattedDateTime()
    {
        $dt = $this->getDateTime();
        return $dt->format('l, F j, Y \a\t g:i A');
    }

    /**
     * Get time until appointment in minutes
     */
    public function getMinutesUntilAppointment()
    {
        $now = new \DateTime();
        $appointmentTime = $this->getDateTime();

        if ($appointmentTime <= $now) {
            return 0;
        }

        $interval = $now->diff($appointmentTime);
        return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['user_id' => 'consultant_id']);
    }

    public function getPatient()
    {
        return $this->hasOne(User::class, ['id' => 'patient_id']);
    }

    // Get user (patient) Profile
    public function getPatientProfile()
    {
        return $this->hasOne(UserProfile::class, ['user_id' => 'patient_id']);
    }

    public function getNotifications()
    {
        return $this->hasMany(AppointmentNotifications::class, ['appointment_id' => 'id']);
    }

    /**
     * {@inheritdoc}
     * @return \frontend\queries\AppointmentsQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \frontend\queries\AppointmentsQuery(get_called_class());
    }

    // output array of status labels
    public static function getStatusList()
    {
        return [
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_NO_SHOW => 'No Show',
            self::STATUS_CONFIRMED => 'Confirmed',
        ];
    }


}