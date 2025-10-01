<?php

namespace frontend\models;

use frontend\models\User;
use frontend\services\NotificationService;
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
 * @property string|null $status (scheduled, completed, cancelled, no_show)
 */
class Appointments extends \yii\db\ActiveRecord
{
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_NO_SHOW = 'no_show';

    private $_oldAttributes = [];

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
            [['status'], 'in', 'range' => [self::STATUS_SCHEDULED, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW]],
            [['status'], 'default', 'value' => self::STATUS_SCHEDULED],
        ];
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
            'patient_id' => Yii::t('app', 'Patient ID'),
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
        ];
    }

    /**
     * Store old attributes before update
     */
    public function afterFind()
    {
        parent::afterFind();
        $this->_oldAttributes = $this->attributes;
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

        // Check if date or time changed
        if (isset($changedAttributes['date']) || isset($changedAttributes['time'])) {
            $rescheduleNeeded = true;
        }

        // Check if status changed to cancelled
        if (isset($changedAttributes['status']) && $this->status === self::STATUS_CANCELLED) {
            $cancelNeeded = true;
        }

        // Check if patient or consultant changed
        if (isset($changedAttributes['patient_id']) || isset($changedAttributes['consultant_id'])) {
            $rescheduleNeeded = true;
        }

        if ($cancelNeeded) {
            NotificationService::cancelNotificationsForAppointment($this->id);
        } elseif ($rescheduleNeeded) {
            NotificationService::rescheduleNotificationsForAppointment($this);
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
        ];
    }


}