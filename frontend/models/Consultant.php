<?php

namespace frontend\models;

use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;

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
 * @property string|null $role 
 * @property bool|null $create_user_account 
 * @property string|null $consultant_email 
 * @property string|null $phoneNumber
 * 
 */
class Consultant extends \yii\db\ActiveRecord
{

    public $phoneNumber;

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
            [['license_number', 'speciality', 'sub_speciality', 'kmpdc_registration_number', 'user_id', 'facility', 'physical_address', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id', 'appointment_session_duration', 'working_start_time', 'working_end_time'], 'default', 'value' => null],
            [['speciality', 'sub_speciality', 'physical_address'], 'string'],
            [['user_id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id', 'appointment_session_duration'], 'integer'],
            [['working_start_time', 'working_end_time'], 'safe'],
            ['names', 'required'],
            [['names'], 'string', 'max' => 150],
            [['names'],'validateNames'],
            [['license_number'], 'string', 'max' => 100],
            [['kmpdc_registration_number'], 'string', 'max' => 255],
            [['facility'], 'string', 'max' => 250],
            [['license_number', 'speciality', 'physical_address'], 'required'],
            [['license_type', 'practice_type'], 'string'],
            ['role', 'string'],
            ['create_user_account','boolean'],
            ['consultant_email','email'],
            [
                'consultant_email',
                'required',
                'when' => function($model) {
                    return (bool)$model->create_user_account;
                },
                'whenClient' => "function (attribute, value) {
                    return $('#consultant-create_user_account').is(':checked');
                }"
            ],
            ['phoneNumber','string','max' => '13'],
            [
                'phoneNumber',
                'required',
                'when' => function($model) {
                    return (bool)$model->create_user_account;
                },
                'whenClient' => "function (attribute, value) {
                    return $('#consultant-create_user_account').is(':checked');
                }"
            ],
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
     * Validates that names are in the format:
     * Title Firstname Secondname Lastname
     *
     * @param string $attribute
     * @param array $params
     */
    public function validateNames($attribute, $params)
    {
        // Remove extra spaces
        $names = preg_replace('/\s+/', ' ', trim($this->$attribute));

        $parts = explode(' ', $names);

        if (count($parts) < 4) {
            $this->addError(
                $attribute,
                'Please enter at least a title, first name, second name and last name.'
            );
            return;
        }

       // Validate title (allows a trailing period)
        if (!preg_match('/^[A-Za-zÀ-ÿ]+\.?$/u', $parts[0])) {
            $this->addError(
                $attribute,
                'Please enter a valid title.'
            );
            return;
        }

        // Validate the remaining names
        foreach (array_slice($parts, 1) as $part) {
            if (!preg_match("/^[A-Za-zÀ-ÿ'-]+$/u", $part)) {
                $this->addError(
                    $attribute,
                    'Names may only contain letters, hyphens and apostrophes.'
                );
                return;
            }
        }
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
        if(parent::beforeSave($insert)) {
            if((bool)$this->create_user_account) {
                    $passwordString = Yii::$app->security->generateRandomString(10);
                    // create a new user and send notification
                    $user = new SignupForm();
                    $user->email = $this->consultant_email;
                    $user->username = self::generateUsername($this->names);
                    $user->password = $passwordString;
                    $user->confirmPassword = $passwordString;

                    $user->role = 'consultant';
                    $user->full_name = $this->names;
                    $user->phone_number = $this->phoneNumber;
                    // Signup the user
                    if($user->signup()) {
                        $createdUser = User::findOne(['email' => $this->consultant_email]);
                        $this->user_id = $createdUser->id;
                        return true;
                    }
                    foreach ($user->getErrors() as $attribute => $errors) {
                        foreach ($errors as $error) {
                            $this->addError('consultant_email', $error);
                        }
                    }
                    return false;
            }
        }
        return true;
    }


    /**
     * Generates a username from a full name.
     *
     * Expected format:
     * Title Firstname Secondname Lastname
     *
     * Example:
     * Dr John Michael Doe
     * Returns:
     * john.michael
     *
     * @param string $names
     * @return string|null
     */
    public static function generateUsername($names)
    {
        // Remove extra spaces
        $names = preg_replace('/\s+/', ' ', trim($names));

        // Split into parts
        $parts = explode(' ', $names);

        // Ensure we have at least:
        // Title Firstname Secondname Lastname
        if (count($parts) < 4) {
            return null;
        }

        $firstName = strtolower($parts[1]);
        $secondName = strtolower($parts[2]);

        // Remove any non-alphanumeric characters
        $firstName = preg_replace('/[^a-z0-9]/i', '', $firstName);
        $secondName = preg_replace('/[^a-z0-9]/i', '', $secondName);

        return "{$firstName}.{$secondName}";
    }

}
