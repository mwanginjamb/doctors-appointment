<?php

namespace frontend\models;

use Yii;
use common\models\User;
use frontend\models\Provider;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "user_profile".
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $phone
 * @property string|null $email
 * @property int|null $cash
 * @property array|null $insurance
 * @property string|null $dob
 * @property int|null $gender
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property User $user
 */
class UserProfile extends \yii\db\ActiveRecord
{

    public $insurance_names; // virtual attribute to hold provider names

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'user_profile';
    }

    public function behaviors()
    {
        return [
            \yii\behaviors\TimestampBehavior::class,
            \yii\behaviors\BlameableBehavior::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'phone', 'email', 'cash', 'insurance', 'gender', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'default', 'value' => null],
            [['user_id', 'cash', 'gender', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
            ['user_id', 'unique'],
            [['dob'], 'required'],
            [['dob'], 'date', 'format' => 'php:Y-m-d'],
            // Ensure dob is not in the future
            ['dob', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'type' => 'date', 'message' => 'Date of Birth cannot be in the future.'],
            // Ensure user is at least 18 years old
            ['dob', 'validateAge'],
            [['phone'], 'string', 'min' => 10, 'max' => 15],

            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
            // [['insurance'], 'string', 'max' => 100],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],

            [['insurance'], 'each', 'rule' => ['string']],
            ['insurance', 'required'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'phone' => 'Phone',
            'email' => 'Email',
            'cash' => 'Cash',
            'insurance' => 'Insurance',
            'dob' => 'Dob',
            'gender' => 'Gender',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'created_by' => 'Created By',
            'updated_by' => 'Updated By',
        ];
    }

    // Validate that the user is at least 18 years old
    public function validateAge($attribute, $params)
    {
        if (empty($this->$attribute)) {
            return; // let 'required' handle this
        }

        try {
            $dob = new \DateTime($this->$attribute);
        } catch (\Exception $e) {
            $this->addError($attribute, 'Invalid date format.');
            return;
        }

        $today = new \DateTime('today'); // normalize
        $minAgeDate = (clone $today)->modify('-18 years'); // latest allowed DOB

        if ($dob > $minAgeDate) {
            $this->addError($attribute, 'User must be at least 18 years old (born on or before ' . $minAgeDate->format('Y-m-d') . ') .');
        }
    }



    /**
     * Gets query for [[User]].
     *
     * @return \yii\db\ActiveQuery|UserQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * {@inheritdoc}
     * @return UserProfileQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new UserProfileQuery(get_called_class());
    }

    // convert insurance array to comma-separated string before saving
    public function beforeSave($insert)
    {
        if (is_array($this->insurance)) {
            $this->insurance = implode(',', $this->insurance);
        }
        return parent::beforeSave($insert);
    }

    public function afterFind()
    {
        parent::afterFind();
        if (!empty($this->insurance)) {
            $ids = explode(',', $this->insurance);

            // Attributes for the form
            $this->insurance = $ids;
            // Fetch provider names
            $names = ArrayHelper::getColumn(Provider::find()->where(['id' => $ids])->asArray()->all(), 'provider');

            // virtual attribute for the view
            $this->insurance_names = implode(', ', $names);
        }
        return $this;
    }

}
