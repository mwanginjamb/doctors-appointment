<?php

namespace frontend\models;

use common\models\User;
use frontend\components\FrontDesk;
use Yii;
use yii\base\Model;

/**
 * Admin form to create a front-desk account, or edit one, and choose the consultants they book for.
 * Front desk signs in with their email address as the username.
 */
class FrontDeskStaffForm extends Model
{
    const SCENARIO_CREATE = 'create';

    public $full_name;
    public $email;
    public $phone;
    public $password;
    /** @var int[] consultant.id values */
    public $consultant_ids = [];

    /** @var User|null the account being edited, or the one just created */
    private $user;

    public function __construct(?User $user = null, $config = [])
    {
        $this->user = $user;
        if ($user) {
            $this->full_name = $user->full_name;
            $this->email = $user->email;
            $this->phone = $user->phone_number;
            $this->consultant_ids = FrontDeskAssignment::consultantIdsFor($user->id);
        }
        parent::__construct($config);
        $this->scenario = $user ? self::SCENARIO_DEFAULT : self::SCENARIO_CREATE;
    }

    public function scenarios()
    {
        $editable = ['full_name', 'phone', 'password', 'consultant_ids'];
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
            [['consultant_ids'], 'default', 'value' => []],
            [['consultant_ids'], 'each', 'rule' => ['exist', 'targetClass' => Consultant::class, 'targetAttribute' => 'id']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name'),
            'email' => Yii::t('app', 'Email (they sign in with it)'),
            'phone' => Yii::t('app', 'Phone'),
            'password' => $this->user ? Yii::t('app', 'New password (leave empty to keep)') : Yii::t('app', 'Initial password'),
            'consultant_ids' => Yii::t('app', 'Books for these consultants'),
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

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $user = $this->user ?? new User();
            if ($user->isNewRecord) {
                $user->username = $this->email;
                $user->email = $this->email;
                $user->role = FrontDesk::ROLE;
                $user->status = User::STATUS_ACTIVE;
                $user->generateAuthKey();
            }
            $user->full_name = $this->full_name;
            $user->phone_number = $this->phone;
            if ($this->password !== null && $this->password !== '') {
                $user->setPassword($this->password);
            }
            if (!$user->save(false)) {
                throw new \RuntimeException('Could not save the front-desk account.');
            }
            FrontDeskAssignment::replaceFor($user->id, (array) $this->consultant_ids);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->user = $user;
        return true;
    }
}
