<?php

namespace frontend\models;

use common\models\User;
use frontend\components\FrontDesk;
use Yii;
use yii\base\Model;

/**
 * Admin form to create a front-desk account, or edit one, and choose the facility they work at and
 * the consultants they book for there (F8 phase 2). Front desk signs in with their email address as
 * the username.
 */
class FrontDeskStaffForm extends Model
{
    const SCENARIO_CREATE = 'create';

    public $full_name;
    public $email;
    public $phone;
    public $password;
    /** @var int|null facility.id: where they work; they see and book only appointments there */
    public $facility_id;
    /** @var int[] consultant.id values, each engaged at $facility_id */
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
            $this->facility_id = $user->facility_id !== null ? (int) $user->facility_id : null;
            $this->consultant_ids = FrontDeskAssignment::consultantIdsFor($user->id);
        }
        parent::__construct($config);
        $this->scenario = $user ? self::SCENARIO_DEFAULT : self::SCENARIO_CREATE;
    }

    public function scenarios()
    {
        $editable = ['full_name', 'phone', 'password', 'facility_id', 'consultant_ids'];
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
            [['facility_id'], 'required', 'message' => Yii::t('app', 'Choose the facility they work at.')],
            [['facility_id'], 'integer'],
            [['facility_id'], 'exist', 'targetClass' => Facility::class, 'targetAttribute' => 'id'],
            [['consultant_ids'], 'default', 'value' => []],
            [['consultant_ids'], 'each', 'rule' => ['exist', 'targetClass' => Consultant::class, 'targetAttribute' => 'id']],
            [['consultant_ids'], 'validateEngagedAtFacility', 'skipOnEmpty' => true],
        ];
    }

    /**
     * Each chosen consultant works at the chosen facility.
     */
    public function validateEngagedAtFacility($attribute)
    {
        if ($this->hasErrors('facility_id') || !$this->facility_id) {
            return;
        }
        $ids = array_map('intval', (array) $this->consultant_ids);
        $engaged = array_map('intval', ConsultantFacility::find()->select('consultant_id')
            ->where(['facility_id' => (int) $this->facility_id, 'consultant_id' => $ids])->column());
        $missing = array_diff($ids, $engaged);
        if ($missing) {
            $names = Consultant::find()->select('names')->where(['id' => $missing])->orderBy(['names' => SORT_ASC])->column();
            $facility = Facility::findOne((int) $this->facility_id);
            $this->addError($attribute, Yii::t('app', '{names} {verb} not work at {facility}. Choose consultants who work there, or add the facility on their Availability page first.', [
                'names' => implode(', ', $names),
                'verb' => count($names) === 1 ? 'does' : 'do',
                'facility' => $facility->name ?? '',
            ]));
        }
    }

    /**
     * Consultants for the checkboxes: consultant.id => "Name (facilities they work at)", and for
     * each facility the consultants who work there, so the page can show only those.
     *
     * @return array{0: array, 1: array} [labels, facility id => consultant ids]
     */
    public static function consultantOptions()
    {
        $labels = [];
        $byFacility = [];
        foreach (Consultant::find()->with('engagements.facility')->orderBy(['names' => SORT_ASC])->all() as $consultant) {
            $labels[$consultant->id] = $consultant->names . ($consultant->getFacilityNames() ? ' (' . $consultant->getFacilityNames() . ')' : '');
            foreach ($consultant->engagements as $engagement) {
                $byFacility[(int) $engagement->facility_id][] = (int) $consultant->id;
            }
        }

        return [$labels, $byFacility];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name'),
            'email' => Yii::t('app', 'Email (they sign in with it)'),
            'phone' => Yii::t('app', 'Phone'),
            'password' => $this->user ? Yii::t('app', 'New password (leave empty to keep)') : Yii::t('app', 'Initial password'),
            'facility_id' => Yii::t('app', 'Works at'),
            'consultant_ids' => Yii::t('app', 'Books for these consultants there'),
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
            $user->facility_id = (int) $this->facility_id;
            if ($this->password !== null && $this->password !== '') {
                $user->setPassword($this->password);
            }
            if (!$user->save(false)) {
                throw new \RuntimeException('Could not save the front-desk account.');
            }
            FrontDeskAssignment::replaceFor($user->id, (array) $this->consultant_ids);
            $transaction->commit();
            FrontDesk::reset();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->user = $user;
        return true;
    }
}
