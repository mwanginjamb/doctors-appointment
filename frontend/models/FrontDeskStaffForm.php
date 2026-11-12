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
 *
 * Every consultant can be ticked. One who does not work at the facility yet is invited to
 * (FacilityInvitation); front desk can book for them there once they accept by adding the facility,
 * with their hours there.
 *
 * A facility admin uses it for their own facility only: $lockedFacilityId fixes the facility.
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
    /** @var int[] consultant.id values */
    public $consultant_ids = [];

    /** @var int|null a facility admin's facility: the only one the form can set */
    public $lockedFacilityId;

    /** @var User|null the account being edited, or the one just created */
    private $user;

    /** @var Consultant[] consultants invited to the facility by the last save */
    private $invited = [];

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
        if ($this->lockedFacilityId !== null) {
            $this->facility_id = (int) $this->lockedFacilityId;
        }
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
            // A facility admin cannot move front desk to another facility, whatever is posted
            [['facility_id'], 'filter', 'filter' => function ($value) {
                return $this->lockedFacilityId !== null ? (int) $this->lockedFacilityId : $value;
            }],
            [['facility_id'], 'required', 'message' => Yii::t('app', 'Choose the facility they work at.')],
            [['facility_id'], 'integer'],
            [['facility_id'], 'exist', 'targetClass' => Facility::class, 'targetAttribute' => 'id'],
            [['consultant_ids'], 'default', 'value' => []],
            [['consultant_ids'], 'each', 'rule' => ['exist', 'targetClass' => Consultant::class, 'targetAttribute' => 'id']],
        ];
    }

    /**
     * Every consultant for the checkboxes, as consultant.id => "Name (facilities they work at)", and
     * for each facility the consultants who work there and those invited, so the page marks the
     * others "not here yet".
     *
     * @return array{0: array, 1: array, 2: array} [labels, facility id => consultant ids working there,
     *               facility id => consultant ids invited]
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
        $invited = [];
        foreach (FacilityInvitation::find()->select(['facility_id', 'consultant_id'])->where(['status' => FacilityInvitation::STATUS_PENDING])->asArray()->all() as $row) {
            $invited[(int) $row['facility_id']][] = (int) $row['consultant_id'];
        }

        return [$labels, $byFacility, $invited];
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

    /**
     * A posted form with no consultants ticked sends no consultant_ids at all when the empty
     * "nothing ticked" field does not come through: read that as "none", not "unchanged", so the
     * last consultant can be unticked.
     */
    public function load($data, $formName = null)
    {
        $scope = $formName ?? $this->formName();
        if (!parent::load($data, $formName)) {
            return false;
        }
        $posted = $scope === '' ? $data : ($data[$scope] ?? []);
        if (!array_key_exists('consultant_ids', (array) $posted)) {
            $this->consultant_ids = [];
        }

        return true;
    }

    public function getUser()
    {
        return $this->user;
    }

    /** @return Consultant[] consultants the last save invited to work at the facility */
    public function getInvited()
    {
        return $this->invited;
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
            $ids = array_values(array_unique(array_map('intval', (array) $this->consultant_ids)));
            FrontDeskAssignment::replaceFor($user->id, $ids);

            // Consultants who do not work here yet are invited to
            $this->invited = [];
            foreach ($ids as $consultantId) {
                if ($invitation = FacilityInvitation::invite($this->facility_id, $consultantId)) {
                    $this->invited[] = $invitation->consultant;
                }
            }
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
