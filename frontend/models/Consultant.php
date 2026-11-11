<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use frontend\models\Provider;
use Yii;
use common\models\User;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "consultant".
 *
 * @property int $id
 * @property string|null $names
 * @property string|null $license_number
 * @property string|null $speciality
 * @property string|null $sub_speciality
 * @property string|null $kmpdc_registration_number
 * @property int|null $user_id
 * @property string|null $facility legacy free text; names the first facility of a new consultant (see ConsultantFacility)
 * @property string|null $physical_address
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $consultant_id
 * @property int|null $appointment_session_duration
 * @property string|null $working_start_time unused; see ConsultantWorkingHours
 * @property string|null $working_end_time unused; see ConsultantWorkingHours
 * @property string|null $practice_type
 * @property string|null $consultant_email
 * @property string|null $consultant_phone_number
 * @property string|null $gender
 * @property string|null $consultant_phone_number
 * @property string|null $practice_name
 * @property string|null $working_hours unused free text; see ConsultantWorkingHours
 * @property string|null $covers_supported
 * @property string|null $device_token
 * @property string|null $device_type
 * @property string|null $token_updated_at
 * @property string|null $practice_establishment_date
 */
class Consultant extends \yii\db\ActiveRecord
{
    public $covers_supported_names;

    /**
     * @var string[]|null sub-specialty names for the form. NULL means "not loaded / not submitted",
     * in which case saving leaves the stored sub-specialties untouched.
     */
    public $sub_specialities;

    const DEVICE_TYPE_ANDROID = 'android';
    const DEVICE_TYPE_IOS = 'ios';
    const DEVICE_TYPE_WEB = 'web';

    const DEFAULT_WORKING_HOURS = '0800hrs - 1700hrs';

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            // Device tokens identify a phone for push messages: not worth keeping
            'audit' => [
                'class' => AuditBehavior::class,
                'entityType' => 'consultant',
                'exclude' => ['device_token', 'device_type', 'token_updated_at'],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'consultant';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['names', 'license_number', 'speciality', 'sub_speciality', 'kmpdc_registration_number', 'user_id', 'facility', 'physical_address', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id', 'appointment_session_duration', 'working_start_time', 'working_end_time'], 'default', 'value' => null],
            [['speciality', 'sub_speciality', 'physical_address'], 'string'],
            [['user_id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id', 'appointment_session_duration'], 'integer'],
            [['working_start_time', 'working_end_time'], 'safe'],
            [['names'], 'string', 'max' => 150],
            [['license_number'], 'string', 'max' => 100],
            [['kmpdc_registration_number'], 'string', 'max' => 255],
            [['facility'], 'string', 'max' => 250],
            [['names', 'license_number', 'speciality', 'physical_address'], 'required'],
            [['license_type', 'practice_type'], 'string'],
            ['consultant_email', 'email'],
            ['consultant_email', 'required'],
            ['consultant_email', 'string', 'max' => 150],
            ['consultant_phone_number', 'string', 'max' => 15],
            ['consultant_phone_number', 'required'],
            ['gender', 'integer'],
            ['practice_name', 'string', 'max' => 150],
            ['working_hours', 'string'],
            [['covers_supported'], 'each', 'rule' => ['string']],
            ['covers_supported', 'required'],

            // Names typed in a different case are matched to the catalogue spelling
            ['speciality', 'filter', 'filter' => function ($value) {
                return $value === null ? null : (self::canonicalNames([$value])[0] ?? null);
            }],
            ['speciality', 'required'], // again, in case the filter reduced whitespace to nothing
            ['sub_specialities', 'filter', 'filter' => [self::class, 'canonicalNames'], 'skipOnEmpty' => false, 'when' => function ($model) {
                return $model->sub_specialities !== null;
            }],
            ['sub_specialities', 'each', 'rule' => ['string', 'max' => 250]],

            // Device token validation
            ['device_token', 'string', 'max' => 255],
            ['device_token', 'trim'],
            ['device_token', 'validateDeviceToken'],

            // Device type validation
            ['device_type', 'string'],
            [
                'device_type',
                'in',
                'range' => [
                    self::DEVICE_TYPE_ANDROID,
                    self::DEVICE_TYPE_IOS,
                    self::DEVICE_TYPE_WEB
                ]
            ],
            // Token updated at validation
            ['token_updated_at', 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            ['token_updated_at', 'default', 'value' => null],
            ['practice_establishment_date', 'date', 'format' => 'php:Y-m-d'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        // Ownership, audit and device fields are set by the system, never from form input
        // sub_speciality is the text copy of $sub_specialities, kept in sync on save
        $protected = ['user_id', 'consultant_id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'device_token', 'device_type', 'token_updated_at', 'sub_speciality'];
        $scenarios[self::SCENARIO_DEFAULT] = array_map(function ($attribute) use ($protected) {
            return in_array($attribute, $protected, true) ? '!' . $attribute : $attribute;
        }, $scenarios[self::SCENARIO_DEFAULT]);

        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'names' => Yii::t('app', 'Names'),
            'license_number' => Yii::t('app', 'License Number'),
            'speciality' => Yii::t('app', 'Speciality'),
            'sub_speciality' => Yii::t('app', 'Sub Speciality'),
            'sub_specialities' => Yii::t('app', 'Sub-specialities'),
            'kmpdc_registration_number' => Yii::t('app', 'Kmpdc Registration Number'),
            'user_id' => Yii::t('app', 'User ID'),
            'facility' => Yii::t('app', 'Facility'),
            'physical_address' => Yii::t('app', 'Physical Address'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_by' => Yii::t('app', 'Created By'),
            'updated_by' => Yii::t('app', 'Updated By'),
            'consultant_id' => Yii::t('app', 'Consultant ID'),
            'appointment_session_duration' => Yii::t('app', 'Appointment Session Duration'),
            'working_start_time' => Yii::t('app', 'Working Start Time'),
            'working_end_time' => Yii::t('app', 'Working End Time'),
        ];
    }


    /**
     * Custom validator for device token format
     */
    public function validateDeviceToken($attribute, $params)
    {
        if (empty($this->$attribute)) {
            return;
        }

        $token = $this->$attribute;

        // Minimum length check
        if (strlen($token) < 50) {
            $this->addError($attribute, 'Device token appears to be invalid (too short).');
            return;
        }

        // Maximum length already handled by string rule with max

        // Character validation
        if (!preg_match('/^[a-zA-Z0-9_:\-]+$/', $token)) {
            $this->addError($attribute, 'Device token contains invalid characters.');
            return;
        }
    }

    // Find User assciated with the consultant

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * False once an admin deactivates the consultant's account: they can no longer be booked.
     */
    public function isTakingBookings()
    {
        return $this->user !== null && (int) $this->user->status === User::STATUS_ACTIVE;
    }

    // Get Gender
    public function getGenderIdentity()
    {
        return $this->hasOne(Gender::class, ['id' => 'gender']);
    }

    /* calculate consultant experience base on model->practice_establishment_date in years and months
     * if practice_establishment_date  is not set return 0
     */
    public function getExperience()
    {
        if (empty($this->practice_establishment_date)) {
            return 0;
        }
        $today = date('Y-m-d');
        $diff = date_diff(date_create($this->practice_establishment_date), date_create($today));
        return $diff->y . ' years ' . $diff->m . ' months';
    }

    /**
     * {@inheritdoc}
     * @return \frontend\queries\ConsultantQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \frontend\queries\ConsultantQuery(get_called_class());
    }

    public function beforeSave($insert)
    {
        if (is_array($this->covers_supported)) {
            $this->covers_supported = implode(',', $this->covers_supported);
        }
        if ($this->sub_specialities !== null) {
            $this->sub_speciality = $this->sub_specialities ? implode(', ', $this->sub_specialities) : null;
        }
        return parent::beforeSave($insert);
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        if ($this->sub_specialities !== null) {
            $this->syncSubSpecialities($this->sub_specialities);
        }
        // A new consultant starts engaged at the facility on their profile, with the default week
        // there; they change both on the Availability page
        if ($insert) {
            ConsultantFacility::createDefault($this);
            ConsultantWorkingHours::replaceWeek($this->id, ConsultantWorkingHours::DEFAULT_WEEK);
        }
    }

    /**
     * The facilities the consultant is engaged at, by facility name.
     */
    public function getEngagements()
    {
        return $this->hasMany(ConsultantFacility::class, ['consultant_id' => 'id'])
            ->joinWith('facility f', false)
            ->with('facility')
            ->orderBy(['f.name' => SORT_ASC]);
    }

    /**
     * The engagement at a facility, or null when the consultant is not engaged there.
     *
     * @return ConsultantFacility|null
     */
    public function getEngagement($facilityId)
    {
        foreach ($this->engagements as $engagement) {
            if ((int) $engagement->facility_id === (int) $facilityId) {
                return $engagement;
            }
        }

        return null;
    }

    /**
     * Names of the facilities the consultant is engaged at, e.g. "Avenue Clinic, Nairobi Hospital".
     */
    public function getFacilityNames()
    {
        return implode(', ', array_map(function (ConsultantFacility $e) {
            return $e->facility->name ?? '';
        }, $this->engagements));
    }

    public function getWorkingHours()
    {
        return $this->hasMany(ConsultantWorkingHours::class, ['consultant_id' => 'id'])
            ->orderBy(['day_of_week' => SORT_ASC, 'start_time' => SORT_ASC]);
    }

    /**
     * The weekly schedule as ISO day (1 = Monday) => list of [start, end] (HH:MM:SS), at every
     * facility. Closed days are missing.
     */
    public function getWeek()
    {
        $week = [];
        foreach ($this->workingHours as $range) {
            $week[(int) $range->day_of_week][] = [$range->start_time, $range->end_time];
        }

        return $week;
    }

    /**
     * The weekly schedule as ISO day => list of [start, end, facility_id].
     */
    public function getBlocks()
    {
        $week = [];
        foreach ($this->workingHours as $range) {
            $week[(int) $range->day_of_week][] = [$range->start_time, $range->end_time, (int) $range->facility_id];
        }

        return $week;
    }

    /**
     * Opening ranges on a given date (Y-m-d), as [start, end, facility_id]: the weekly blocks at
     * facilities where the engagement runs that day (inside its dates, facility taking bookings).
     *
     * @param int|null $facilityId only the ranges at this facility
     */
    public function getRangesFor($date, $facilityId = null)
    {
        $ranges = [];
        foreach ($this->getBlocks()[(int) date('N', strtotime($date))] ?? [] as $range) {
            if ($facilityId !== null && $range[2] !== (int) $facilityId) {
                continue;
            }
            $engagement = $this->getEngagement($range[2]);
            if ($engagement !== null && $engagement->isRunningOn($date)) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /**
     * The opening range a session starting at $date $time falls in, as [start, end, facility_id],
     * or null when the consultant is not in then. Ranges never overlap, so there is at most one.
     */
    public function rangeAt($date, $time)
    {
        $start = strtotime($date . ' ' . $time);
        foreach ($this->getRangesFor($date) as $range) {
            if ($start >= strtotime($date . ' ' . $range[0]) && $start < strtotime($date . ' ' . $range[1])) {
                return $range;
            }
        }

        return null;
    }

    /**
     * Length of new bookings with this consultant, in minutes. At a facility, the length set for
     * that engagement; otherwise their own default, else the clinic default. Existing appointments
     * keep the length they were booked with.
     *
     * @param int|null $facilityId
     */
    public function getSessionMinutes($facilityId = null)
    {
        if ($facilityId !== null && ($engagement = $this->getEngagement($facilityId)) && $engagement->session_minutes > 0) {
            return (int) $engagement->session_minutes;
        }
        if ($this->appointment_session_duration > 0) {
            return (int) $this->appointment_session_duration;
        }

        return (int) (Yii::$app->params['booking.defaultSessionMinutes'] ?? 30);
    }

    /**
     * Whether a session of $minutes starting at $date $time fits entirely inside one opening range
     * (at $facilityId, when given).
     */
    public function isOpenFor($date, $time, $minutes, $facilityId = null)
    {
        $start = strtotime($date . ' ' . $time);
        $end = $start + $minutes * 60;
        foreach ($this->getRangesFor($date, $facilityId) as [$open, $close]) {
            if ($start >= strtotime($date . ' ' . $open) && $end <= strtotime($date . ' ' . $close)) {
                return true;
            }
        }

        return false;
    }

    /**
     * e.g. "Mon–Sat 08:00–13:00, 14:00–17:00", or null when every day is closed. With hours at
     * several facilities, one summary per facility.
     */
    public function getHoursSummary()
    {
        return ConsultantWorkingHours::describe($this->getBlocks());
    }

    /**
     * Weekly hours per facility, for pages: list of ['engagement' => ConsultantFacility,
     * 'summary' => string|null], by facility name.
     */
    public function getHoursByFacility()
    {
        $byFacility = ConsultantWorkingHours::splitByFacility($this->getBlocks());
        $rows = [];
        foreach ($this->engagements as $engagement) {
            $rows[] = [
                'engagement' => $engagement,
                'summary' => ConsultantWorkingHours::summarize($byFacility[(int) $engagement->facility_id] ?? []),
            ];
        }

        return $rows;
    }

    public function getSubSpecialityLinks()
    {
        return $this->hasMany(ConsultantSubSpeciality::class, ['consultant_id' => 'id'])->orderBy(['name' => SORT_ASC]);
    }

    /**
     * Fills $sub_specialities from the stored links, for editing.
     * @return $this
     */
    public function loadSubSpecialities()
    {
        $this->sub_specialities = $this->isNewRecord ? [] : $this->getSubSpecialityLinks()->select('name')->column();
        return $this;
    }

    /**
     * Replaces this consultant's sub-specialties with $names, linking catalogue entries by name.
     * @param string[] $names
     */
    protected function syncSubSpecialities(array $names)
    {
        $ids = [];
        if ($names) {
            foreach (Specialization::find()->select(['id', 'specialization'])->where(['specialization' => $names])->asArray()->all() as $row) {
                $ids[mb_strtolower($row['specialization'])] = (int) $row['id'];
            }
        }

        $transaction = static::getDb()->beginTransaction();
        try {
            ConsultantSubSpeciality::deleteAll(['consultant_id' => $this->id]);
            foreach ($names as $name) {
                $link = new ConsultantSubSpeciality([
                    'consultant_id' => $this->id,
                    'specialization_id' => $ids[mb_strtolower($name)] ?? null,
                    'name' => $name,
                ]);
                $link->save(false);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Trims, de-duplicates (case-insensitive) and swaps in the catalogue spelling of each name.
     * @param mixed $value array or comma-separated string
     * @return string[]
     */
    public static function canonicalNames($value)
    {
        $names = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);
        $clean = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !isset($clean[mb_strtolower($name)])) {
                $clean[mb_strtolower($name)] = $name;
            }
        }
        if (!$clean) {
            return [];
        }
        foreach (Specialization::find()->select('specialization')->where(['specialization' => array_values($clean)])->column() as $catalogueName) {
            $clean[mb_strtolower($catalogueName)] = $catalogueName;
        }
        return array_values($clean);
    }

    public function afterFind()
    {
        parent::afterFind();
        if (!empty($this->covers_supported)) {
            $ids = explode(',', $this->covers_supported);

            // Attributes for the form
            $this->covers_supported = $ids;
            // Fetch provider names
            $names = ArrayHelper::getColumn(Provider::find()->where(['id' => $ids])->asArray()->all(), 'provider');

            // virtual attribute for the view
            $this->covers_supported_names = implode(', ', $names);
        }
        return $this;
    }

}
