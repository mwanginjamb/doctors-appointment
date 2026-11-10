<?php

namespace frontend\models;

use common\models\User;
use Yii;
use yii\base\Model;

/**
 * The signed-in user's own account details on the My account page: name and phone.
 * Used by staff (front desk, admins), who have no consultant or patient profile.
 */
class AccountForm extends Model
{
    public $full_name;
    public $phone;

    /** @var User */
    private $user;

    public function __construct(User $user, $config = [])
    {
        $this->user = $user;
        $this->full_name = $user->full_name;
        $this->phone = $user->phone_number;
        parent::__construct($config);
    }

    public function rules()
    {
        return [
            [['full_name', 'phone'], 'trim'],
            [['full_name'], 'required'],
            [['full_name'], 'string', 'max' => 150],
            [['phone'], 'string', 'max' => 15],
            [['phone'], 'default', 'value' => null],
        ];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name'),
            'phone' => Yii::t('app', 'Phone'),
        ];
    }

    public function save()
    {
        if (!$this->validate()) {
            return false;
        }
        $this->user->full_name = $this->full_name;
        $this->user->phone_number = $this->phone;

        return $this->user->save(false);
    }
}
