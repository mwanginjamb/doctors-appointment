<?php

namespace frontend\models;

use common\models\User;
use frontend\components\FrontDesk;
use Yii;
use yii\base\Model;

/**
 * Consultancy admin form to create a facility admin account, or edit one. A facility admin runs one
 * facility: its appointments board and numbers, its front-desk staff, its closed days, its contact
 * details and its audit log. They sign in with their email address as the username.
 */
class FacilityAdminForm extends Model
{
    const SCENARIO_CREATE = 'create';

    public $full_name;
    public $email;
    public $phone;
    public $password;
    /** @var int|null facility.id: the facility they run */
    public $facility_id;

    /** @var User|null the account being edited, or the one just created */
    private $user;

    public function __construct(?User $user = null, $config = [])
    {
        $this->user = $user;
        if ($user) {
            $this->full_name = $user->full_name;
            $this->email = $user->email;
            $this->phone = $user->phone_number;
            $this->facility_id = $user->facility_id !== null ? (int) $user->facility_id : null;
        }
        parent::__construct($config);
        $this->scenario = $user ? self::SCENARIO_DEFAULT : self::SCENARIO_CREATE;
    }

    public function scenarios()
    {
        $editable = ['full_name', 'phone', 'password', 'facility_id'];
        return [
            self::SCENARIO_CREATE => array_merge($editable, ['email']),
            self::SCENARIO_DEFAULT => $editable,
        ];
    }

    public function rules()
    {
        return [
            [['full_name', 'email', 'phone'], 'trim'],
            [['full_name'], 'required'],
            [['full_name'], 'string', 'max' => 150],
            [['email'], 'required', 'on' => self::SCENARIO_CREATE],
            [['email'], 'email'],
            [['email'], 'unique', 'targetClass' => User::class, 'on' => self::SCENARIO_CREATE],
            [['email'], 'unique', 'targetClass' => User::class, 'targetAttribute' => 'username', 'on' => self::SCENARIO_CREATE,
                'message' => Yii::t('app', 'An account already uses this email.')],
            [['phone'], 'string', 'max' => 15],
            [['phone'], 'default', 'value' => null],
            [['password'], 'required', 'on' => self::SCENARIO_CREATE],
            [['password'], 'string', 'min' => Yii::$app->params['user.passwordMinLength'] ?? 8],
            [['facility_id'], 'required', 'message' => Yii::t('app', 'Choose the facility they run.')],
            [['facility_id'], 'integer'],
            [['facility_id'], 'exist', 'targetClass' => Facility::class, 'targetAttribute' => 'id'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name'),
            'email' => Yii::t('app', 'Email (they sign in with it)'),
            'phone' => Yii::t('app', 'Phone'),
            'password' => $this->user ? Yii::t('app', 'New password (leave empty to keep)') : Yii::t('app', 'Initial password'),
            'facility_id' => Yii::t('app', 'Runs'),
        ];
    }

    public function getUser()
    {
        return $this->user;
    }

    public function save()
    {
        if (!$this->validate()) {
            return false;
        }

        $user = $this->user ?? new User();
        if ($user->isNewRecord) {
            $user->username = $this->email;
            $user->email = $this->email;
            // The facility-admin RBAC role follows from the account type (User::afterSave)
            $user->role = User::ACCOUNT_FACILITY_ADMIN;
            $user->status = User::STATUS_ACTIVE;
            $user->generateAuthKey();
        }
        $user->full_name = $this->full_name;
        $user->phone_number = $this->phone;
        $user->facility_id = (int) $this->facility_id;
        if ($this->password !== null && $this->password !== '') {
            $user->setPassword($this->password);
        }
        if (!$user->save(false)) {
            throw new \RuntimeException('Could not save the facility admin account.');
        }
        FrontDesk::reset();

        $this->user = $user;
        return true;
    }
}
