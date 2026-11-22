<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use common\components\RbacHelper;
use frontend\models\User;
use frontend\services\AppointmentChangeNotifier;
use frontend\services\BookingService;
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
 * @property int $duration_minutes length it was booked with; set by validateSlot()
 * @property int|null $patient_id
 * @property int|null $speciality_id
 * @property int|null $service_id
 * @property int|null $provider_id
 * @property string|null $location unused free text; see $facility_id
 * @property int|null $facility_id where it takes place; set by BookingService from the consultant's hours
 * @property string|null $fee the consultant's fee at the facility when it was booked
 * @property int|null $recurring_appointment
 * @property int|null $walk_in_appointment
 * @property string|null $symptoms_brief
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $consultant_id
 * @property string|null $status (scheduled, completed, cancelled, no_show, confirmed)
 * @property string|null $cancellation_note why it was cancelled; set only by cancel()
 * @property int|null $outcome_at when attended / no-show was recorded; set only by recordOutcome()
 * @property int|null $outcome_by who recorded it
 * @property int|null $booking_link_id the booking link a remote walk-in booked through
 * @property int|null $patient_age
 * @property string|null $booking_channel F20: online, link, staff or walk_in; set by BookingService::book()
 * @property int|null $cancelled_by F20: who cancelled; null for a guest using the manage link
 * @property string|null $cancelled_party F20: on whose side: patient, consultant, front_desk, facility or system
 * @property int|null $cancelled_at_patient_request F20: 1 when the patient asked for the cancellation
 * @property int|null $cancelled_at
 * @property string|null $payment_mode F22: cash or insurance, asked at booking
 * @property int|null $hold_expires_at F22: awaiting payment, the slot is released after this
 * @property string|null $confirmed_via F13: how the patient confirmed: phone, in_person, message, other (staff), link (the patient)
 * @property int|null $confirmed_by F13: user.id of whoever confirmed it
 * @property int|null $confirmed_at F13
 */
class Appointments extends \yii\db\ActiveRecord
{
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_NO_SHOW = 'no_show';
    const STATUS_CONFIRMED = 'confirmed';
    /** F22: booked, holding its slot while the patient pays the commitment fee */
    const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    /** F22: the fee was not paid in time, so the slot was released; not a cancellation */
    const STATUS_UNPAID = 'unpaid';

    /** F20: how the booking was made. Decides the commitment fee from day 31 of the pilot. */
    const CHANNEL_ONLINE = 'online';    // the patient, signed in or from a waiting-list link
    const CHANNEL_LINK = 'link';        // a booking link staff sent
    const CHANNEL_STAFF = 'staff';      // the consultant or their front desk, for a later day
    const CHANNEL_WALK_IN = 'walk_in';  // the consultant or their front desk, for the same day

    /** F20: on whose side a cancellation was made */
    const PARTY_PATIENT = 'patient';
    const PARTY_CONSULTANT = 'consultant';
    const PARTY_FRONT_DESK = 'front_desk';
    const PARTY_FACILITY = 'facility';
    const PARTY_SYSTEM = 'system';

    /** F20: why staff cancelled; asked in the cancel dialog */
    const REASON_PATIENT_REQUEST = 'patient_request';
    const REASON_FACILITY_CLOSED = 'facility_closed';
    const REASON_CLINIC = 'clinic';

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
        'cancellation_note',
        'duration_minutes',
        'outcome_at',
        'outcome_by',
        'booking_link_id',
        'facility_id',
        'fee',
        'booking_channel',
        'cancelled_by',
        'cancelled_party',
        'cancelled_at_patient_request',
        'cancelled_at',
        'hold_expires_at',
    ];

    /** Days during which a recorded outcome can still be changed (attended <-> no-show). */
    const OUTCOME_CORRECTION_DAYS = 7;

    /**
     * @var bool set for a remote walk-in booked through a booking link: like a front-desk walk-in,
     *           it is limited by the slots the link offers rather than by the minimum notice
     */
    public $skipNotice = false;

    /**
     * @var bool set when the patient changes the appointment through their self-service link (not
     *           signed in), so the change notice goes to the consultant
     */
    public $changedByPatient = false;

    public $patient_age;

    /** @var string|null F22: the phone asked to pay the commitment fee; the patient's when empty */
    public $payPhone;

    /** @var bool F22: the patient accepted the booking terms (asked only when a fee applies) */
    public $feeTermsAccepted = false;

    /** @var array|null F22: [FeeRules decision, reason], settled when a new booking is validated */
    public $feeDecision;

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
            BlameableBehavior::class,
            // The symptoms are health details: the log shows they changed, not what they say
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'appointment', 'masked' => ['symptoms_brief']],
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
            [['patient_id', 'speciality_id', 'service_id', 'provider_id', 'recurring_appointment', 'walk_in_appointment', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id'], 'integer'],
            [['location', 'symptoms_brief'], 'string'],
            [['status'], 'string', 'max' => 20],
            [['booking_channel'], 'in', 'range' => array_keys(self::channelLabels())],
            [['payment_mode'], 'in', 'range' => [\frontend\services\FeeRules::PAYMENT_CASH, \frontend\services\FeeRules::PAYMENT_INSURANCE]],
            [['cancellation_note'], 'string', 'max' => 500],
            [['duration_minutes'], 'integer', 'min' => 5, 'max' => 240],
            [['facility_id'], 'integer'],
            [['fee'], 'number', 'min' => 0],
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
            // F22: after the slot, which settles the facility the fee rules need
            [['time'], 'validateFee', 'when' => function ($model) {
                return $model->isNewRecord;
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
     * The slot must be bookable: see BookingService::slotProblem() for the rules.
     */
    public function validateSlot($attribute, $params)
    {
        if ($this->hasErrors('date') || $this->hasErrors('consultant_id')) {
            return;
        }

        $problem = Yii::createObject(BookingService::class)->slotProblem($this);
        if ($problem !== null) {
            $this->addError($attribute, $problem);
        }
    }

    /**
     * F22: decides whether the new booking carries the commitment fee (FeeRules::decide()). One the
     * patient pays needs the booking terms accepted and a phone to send the M-Pesa prompt to.
     */
    public function validateFee($attribute, $params)
    {
        if ($this->hasErrors()) {
            return;
        }
        if (!$this->booking_channel) {
            $this->booking_channel = Yii::createObject(BookingService::class)->channelOf($this);
        }
        $this->feeDecision = \frontend\services\FeeRules::decide($this);
        if ($this->feeDecision[0] !== \frontend\services\FeeRules::CHARGE) {
            return;
        }
        // F23: a rebooking credit pays it; the booking terms still apply
        $credit = \frontend\services\FeeLifecycle::usableCredit($this->patient_id);
        if ($credit !== null) {
            $this->feeDecision = [\frontend\services\FeeRules::CREDIT, 'Paid with a rebooking credit.', $credit->id];
            if (!$this->feeTermsAccepted) {
                $this->addError('feeTermsAccepted', Yii::t('app', 'Please accept the booking terms: your Ksh {amount} credit pays the commitment fee.', ['amount' => \frontend\services\FeeRules::amount()]));
            }
            return;
        }
        if (!$this->feeTermsAccepted) {
            $this->addError('feeTermsAccepted', Yii::t('app', 'Please accept the booking terms: the Ksh {amount} commitment fee is paid by M-Pesa now.', ['amount' => \frontend\services\FeeRules::amount()]));
        }
        $phone = trim((string) $this->payPhone) !== '' ? $this->payPhone : (User::find()->select('phone_number')->where(['id' => $this->patient_id])->scalar() ?: '');
        $normalized = \common\helpers\Phone::normalize($phone);
        if ($normalized === null || strpos($normalized, \common\helpers\Phone::DEFAULT_COUNTRY) !== 0) {
            $this->addError('payPhone', Yii::t('app', 'Enter the Kenyan M-Pesa number that pays the Ksh {amount} commitment fee.', ['amount' => \frontend\services\FeeRules::amount()]));
            return;
        }
        $this->payPhone = $normalized;
    }

    /** F22: the statuses whose slot is free again: cancelled, and unpaid holds. */
    public static function releasedStatuses()
    {
        return [self::STATUS_CANCELLED, self::STATUS_UNPAID];
    }

    /**
     * Appointments that hold their slot: everything but cancelled and unpaid ones.
     *
     * @param string $column the status column, e.g. "a.status"
     */
    public static function activeCondition($column = 'status')
    {
        return ['or', [$column => null], ['not in', $column, self::releasedStatuses()]];
    }

    public function isAwaitingPayment()
    {
        return $this->status === self::STATUS_AWAITING_PAYMENT;
    }

    /**
     * This appointment's length in minutes: the length stored when it was booked. Before it is
     * booked, the consultant's current session length.
     */
    public function getDurationMinutes()
    {
        if ($this->duration_minutes > 0) {
            return (int) $this->duration_minutes;
        }

        return $this->consultant ? $this->consultant->getSessionMinutes($this->facility_id) : self::defaultSessionMinutes();
    }

    /** The clinic-wide session length, for consultants who have not chosen one. */
    public static function defaultSessionMinutes()
    {
        return (int) (Yii::$app->params['booking.defaultSessionMinutes'] ?? 30);
    }

    /**
     * Saves the booking with the consultant locked (BookingService::save()). Kept for callers that
     * hold an Appointments; new code books and moves through BookingService.
     */
    public function saveBooking()
    {
        return Yii::createObject(BookingService::class)->save($this);
    }

    /**
     * Only upcoming appointments that are still on (scheduled or confirmed) can be cancelled.
     * Past ones are left for completed / no-show.
     */
    // F13: how the patient confirmed. Staff pick one of the first four; the patient's link records the last
    const CONFIRMED_PHONE = 'phone';
    const CONFIRMED_IN_PERSON = 'in_person';
    const CONFIRMED_MESSAGE = 'message';
    const CONFIRMED_OTHER = 'other';
    const CONFIRMED_LINK = 'link';

    /** F13: what staff choose from when confirming, value => label. */
    public static function confirmedViaChoices()
    {
        return [
            self::CONFIRMED_PHONE => 'By phone',
            self::CONFIRMED_IN_PERSON => 'In person',
            self::CONFIRMED_MESSAGE => 'By WhatsApp or SMS',
            self::CONFIRMED_OTHER => 'Other',
        ];
    }

    public static function confirmedViaLabels()
    {
        return self::confirmedViaChoices() + [self::CONFIRMED_LINK => 'From their reminder link'];
    }

    /** F13: a scheduled appointment still to come can be confirmed. */
    public function canBeConfirmed()
    {
        return ($this->status === null || $this->status === self::STATUS_SCHEDULED) && $this->isFuture();
    }

    /**
     * F13: confirms it, recording how and by whom. afterSave() sends the patient the confirmation.
     *
     * @param string $via a CONFIRMED_* value
     * @param int|null $userId who confirmed it
     */
    public function confirm($via, $userId)
    {
        if (!$this->canBeConfirmed() || !array_key_exists($via, self::confirmedViaLabels())) {
            return false;
        }
        $this->status = self::STATUS_CONFIRMED;
        $this->confirmed_via = $via;
        $this->confirmed_by = $userId;
        $this->confirmed_at = time();

        return $this->save(false);
    }

    /** F13: e.g. "Confirmed by phone, by Mary Wanjiru, 3 Dec 2026 10:15", or null before F13. */
    public function getConfirmationLabel()
    {
        if ($this->status !== self::STATUS_CONFIRMED || !$this->confirmed_via) {
            return null;
        }
        $label = self::confirmedViaLabels()[$this->confirmed_via] ?? $this->confirmed_via;
        $who = $this->confirmed_by ? \common\models\User::findOne($this->confirmed_by) : null;
        $parts = [$label];
        if ($who !== null && $this->confirmed_via !== self::CONFIRMED_LINK) {
            $parts[] = 'by ' . ($who->full_name ?: $who->username);
        }
        if ($this->confirmed_at) {
            $parts[] = date('j M Y H:i', $this->confirmed_at);
        }

        return implode(', ', $parts);
    }

    public function canBeCancelled()
    {
        $active = $this->status === null || in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED, self::STATUS_AWAITING_PAYMENT], true);

        return $active && $this->isFuture();
    }

    /**
     * Cancels the appointment and keeps the record. afterSave() cancels its pending reminders and
     * the slot becomes free. F20 records who cancelled, on whose side, and whether the patient
     * asked: the refund and the Ksh 100 charge depend on them.
     *
     * The side follows from the signed-in user: a patient (or a guest using the manage link) cancels
     * at their own request; staff say why with $reason, one of the REASON_* constants. A facility
     * closure puts the cancellation on the facility's side, whoever made it.
     *
     * @param string|null $note optional reason, up to 500 characters
     * @param string|null $reason staff only: REASON_PATIENT_REQUEST, REASON_FACILITY_CLOSED or REASON_CLINIC
     * @return bool false when the appointment cannot be cancelled or the note is invalid (see getErrors())
     */
    public function cancel($note = null, $reason = null)
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $note = trim((string) $note);
        $this->cancellation_note = $note === '' ? null : $note;
        if (!$this->validate(['cancellation_note'])) {
            return false;
        }

        $user = Yii::$app->get('user', false);
        $identity = $user && !$user->isGuest ? $user->identity : null;
        $party = self::partyOf($identity);
        if ($party === self::PARTY_PATIENT) {
            $atRequest = true;
        } elseif ($reason === self::REASON_FACILITY_CLOSED) {
            $party = self::PARTY_FACILITY;
            $atRequest = false;
        } else {
            $atRequest = $reason === self::REASON_PATIENT_REQUEST;
        }

        $wasHeld = $this->isAwaitingPayment();
        $this->status = self::STATUS_CANCELLED;
        $this->hold_expires_at = null;
        $this->cancelled_by = $identity ? $identity->id : null;
        $this->cancelled_party = $party;
        $this->cancelled_at_patient_request = $atRequest ? 1 : 0;
        $this->cancelled_at = time();
        if (!$this->save(false)) {
            return false;
        }
        if ($wasHeld) {
            // F22: a held booking cancelled before payment: its pending fee goes too
            \frontend\services\CommitmentFees::cancelPending($this);
        } else {
            // F23: a paid or absorbed fee is credited, cancelled or forfeited
            \frontend\services\FeeLifecycle::onCancelled($this);
            // F24: so is the front desk's incentive
            \frontend\services\Incentives::onCancelled($this);
            // F25: the Ksh 100 charge when the consultant or their front desk cancelled
            \frontend\services\Invoices::onCancelled($this);
        }

        return true;
    }

    /**
     * The side a user cancels for: a patient account, or a guest using the manage link, is the
     * patient; staff are their own role; a platform admin is the system.
     *
     * @param \common\models\User|null $identity
     */
    public static function partyOf($identity)
    {
        if ($identity === null || $identity->isPatientAccount()) {
            return self::PARTY_PATIENT;
        }
        if ($identity->isConsultantAccount()) {
            return self::PARTY_CONSULTANT;
        }
        if ($identity->isFrontDeskAccount()) {
            return self::PARTY_FRONT_DESK;
        }
        if ($identity->isFacilityAdminAccount()) {
            return self::PARTY_FACILITY;
        }

        return self::PARTY_SYSTEM;
    }

    /** Whether the cancel dialog asks this user why: everyone signed in but patients. */
    public static function asksCancelReason($identity)
    {
        return $identity !== null && self::partyOf($identity) !== self::PARTY_PATIENT;
    }

    /** The staff answers to "Why is it cancelled?" */
    public static function cancelReasonLabels()
    {
        return [
            self::REASON_PATIENT_REQUEST => Yii::t('app', 'The patient asked'),
            self::REASON_FACILITY_CLOSED => Yii::t('app', 'The facility is closed that day'),
            self::REASON_CLINIC => Yii::t('app', "The clinic's decision (the consultant cannot see the patient)"),
        ];
    }

    public static function channelLabels()
    {
        return [
            self::CHANNEL_ONLINE => Yii::t('app', 'Online'),
            self::CHANNEL_LINK => Yii::t('app', 'Booking link'),
            self::CHANNEL_STAFF => Yii::t('app', 'Booked by staff'),
            self::CHANNEL_WALK_IN => Yii::t('app', 'Walk-in'),
        ];
    }

    /**
     * Whether attended / no-show can be recorded now: the appointment has started and is still
     * scheduled or confirmed, or its outcome was recorded within the last OUTCOME_CORRECTION_DAYS
     * (to fix a wrong mark). Who may record it is checked by the controller.
     */
    public function canRecordOutcome()
    {
        if (strtotime($this->date . ' ' . $this->time) > time()) {
            return false;
        }
        if ($this->status === null || in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED], true)) {
            return true;
        }

        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_NO_SHOW], true)
            && $this->outcome_at !== null
            && $this->outcome_at > time() - self::OUTCOME_CORRECTION_DAYS * 86400;
    }

    /**
     * Records that the patient attended (STATUS_COMPLETED) or did not come (STATUS_NO_SHOW).
     *
     * @param string $outcome STATUS_COMPLETED or STATUS_NO_SHOW
     * @return bool false when the outcome is not allowed now (see canRecordOutcome())
     */
    public function recordOutcome($outcome)
    {
        if (!in_array($outcome, [self::STATUS_COMPLETED, self::STATUS_NO_SHOW], true) || !$this->canRecordOutcome()) {
            return false;
        }

        $user = Yii::$app->get('user', false);
        $this->status = $outcome;
        $this->outcome_at = time();
        $this->outcome_by = $user && !$user->isGuest ? $user->id : null;

        if (!$this->save(false)) {
            return false;
        }
        // F23: a no-show forfeits the commitment fee; F24: attending earns the front desk's incentive
        \frontend\services\FeeLifecycle::onOutcome($this);
        \frontend\services\Incentives::onOutcome($this);

        return true;
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    /**
     * Where it is, for messages: the facility's name, address and map link ("Nairobi Hospital,
     * Argwings Kodhek Rd. Map: https://…"); the clinic's name for appointments without a facility.
     */
    public function getPlaceText()
    {
        return $this->facility ? $this->facility->getDirections() : Yii::$app->name;
    }

    /**
     * The fee recorded when it was booked, e.g. "KES 3,500"; null when none was set.
     */
    public function getFeeLabel()
    {
        return ConsultantFacility::formatFee($this->fee);
    }

    public function getBookingLink()
    {
        return $this->hasOne(BookingLink::class, ['id' => 'booking_link_id']);
    }

    public function getOutcomeRecorder()
    {
        return $this->hasOne(User::class, ['id' => 'outcome_by']);
    }

    /**
     * No-shows of a patient in the last $days days, for staff to see when booking or opening an
     * appointment. Only those the viewer may see in the patient's history count (F8 phase 2):
     * pass FrontDesk::historyCondition().
     *
     * @param array|string $scope condition on the appointments table; [] counts every one
     */
    public static function countNoShows($patientId, $days = 365, $scope = [])
    {
        $query = self::find()
            ->orderBy(null)
            ->where(['appointments.patient_id' => $patientId, 'appointments.status' => self::STATUS_NO_SHOW])
            ->andWhere(['>=', 'appointments.date', date('Y-m-d', strtotime("-{$days} days"))]);
        if ($scope !== [] && $scope !== null) {
            $query->andWhere($scope);
        }

        return (int) $query->count();
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
            'facility_id' => Yii::t('app', 'Facility'),
            'fee' => Yii::t('app', 'Fee'),
            'booking_channel' => Yii::t('app', 'Booked'),
            'cancelled_party' => Yii::t('app', 'Cancelled by'),
            'payment_mode' => Yii::t('app', 'Paying by'),
        ];
    }

    /**
     * F20: a new booking records how it was made.
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        if ($insert && !$this->booking_channel) {
            $this->booking_channel = Yii::createObject(BookingService::class)->channelOf($this);
        }
        // F22: a booking the patient pays for holds its slot until the payment arrives
        if ($insert && ($this->feeDecision[0] ?? null) === \frontend\services\FeeRules::CHARGE) {
            $this->status = self::STATUS_AWAITING_PAYMENT;
            $this->hold_expires_at = time() + 60 * \frontend\services\CommitmentFees::holdMinutes();
        }

        return true;
    }

    /**
     * Handle notification scheduling after insert
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert && $this->consultant_id) {
            // F20: the consultant's pilot starts with their first booking, by any channel, and never
            // moves. Rolled back with the booking when the caller's transaction fails.
            Consultant::updateAll(
                ['first_booking_at' => $this->created_at ?: time()],
                ['user_id' => $this->consultant_id, 'first_booking_at' => null]
            );
        }

        if ($insert) {
            // F22: the fee, paid or absorbed; a booking awaiting payment is confirmed once paid
            \frontend\services\CommitmentFees::recordFor($this);
        }

        $paid = !$insert && ($changedAttributes['status'] ?? null) === self::STATUS_AWAITING_PAYMENT
            && in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED], true);
        if (($insert && !$this->isAwaitingPayment()) || $paid) {
            // Schedule notifications for new appointment
            $this->scheduleNotifications();
            // Send immediate confirmation notification
            NotificationService::sendImmediateConfirmation($this);
        } elseif ($insert) {
            // F22: awaiting payment: nothing is sent until it is paid
        } elseif (($changedAttributes['status'] ?? null) === self::STATUS_AWAITING_PAYMENT) {
            // F22: a held booking cancelled or released: no reminders were scheduled, nothing to tell
            NotificationService::cancelNotificationsForAppointment($this->id);
        } else {
            // Handle updates (reminders)
            $this->handleAppointmentUpdate($changedAttributes);

            // Tell the other side: the patient when the clinic changed it, the consultant when the patient did
            $statusChanged = array_key_exists('status', $changedAttributes) && $changedAttributes['status'] !== $this->status;
            if ($statusChanged && $this->status === self::STATUS_CANCELLED) {
                AppointmentChangeNotifier::notify($this, AppointmentChangeNotifier::CANCELLED);
            } elseif (isset($changedAttributes['date']) || isset($changedAttributes['time'])) {
                // F23: the commitment fee moves with the booking
                \frontend\services\FeeLifecycle::onMoved($this, $changedAttributes['date'] ?? $this->date, $changedAttributes['time'] ?? $this->time);
                AppointmentChangeNotifier::notify(
                    $this,
                    AppointmentChangeNotifier::MOVED,
                    $changedAttributes['date'] ?? null,
                    $changedAttributes['time'] ?? null
                );
            }
        }

        // F4: a freed slot goes to the waiting list; a booking ends the patient's wait
        \frontend\services\Waitlist::appointmentSaved($this, $insert, $changedAttributes);
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
            self::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
            self::STATUS_UNPAID => 'Unpaid (released)',
        ];
    }


}