<?php

namespace frontend\models;

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
 * @property string|null $facility
 * @property string|null $physical_address
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $consultant_id
 * @property int|null $appointment_session_duration
 * @property string|null $working_start_time
 * @property string|null $working_end_time
 * @property string|null $practice_type
 * @property string|null $consultant_email
 * @property string|null $consultant_phone_number
 * @property string|null $gender
 * @property string|null $consultant_phone_number
 * @property string|null $practice_name
 * @property string|null $working_hours
 * @property string|null $covers_supported
 * @property string|null $device_token
 * @property string|null $device_type
 * @property string|null $token_updated_at
 * @property string|null $practice_establishment_date
 */
class Consultant extends \yii\db\ActiveRecord
{
    public $covers_supported_names;

    const DEVICE_TYPE_ANDROID = 'android';
    const DEVICE_TYPE_IOS = 'ios';
    const DEVICE_TYPE_WEB = 'web';

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
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'names' => Yii::t('app', 'Names'),
            'license_number' => Yii::t('app', 'License Number'),
            'speciality' => Yii::t('app', 'Speciality'),
            'sub_speciality' => Yii::t('app', 'Sub Speciality'),
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
     * @return \app\queries\ConsultantQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\queries\ConsultantQuery(get_called_class());
    }

    public function beforeSave($insert)
    {
        if (is_array($this->covers_supported)) {
            $this->covers_supported = implode(',', $this->covers_supported);
        }
        return parent::beforeSave($insert);
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
