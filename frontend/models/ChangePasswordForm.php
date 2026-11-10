<?php

namespace frontend\models;

use common\models\User;
use Yii;
use yii\base\Model;

/**
 * Lets a signed-in user change their password by confirming the current one.
 */
class ChangePasswordForm extends Model
{
    public $current_password;
    public $new_password;
    public $confirm_password;

    /** @var User */
    private $user;

    public function __construct(User $user, $config = [])
    {
        $this->user = $user;
        parent::__construct($config);
    }

    public function rules()
    {
        return [
            [['current_password', 'new_password', 'confirm_password'], 'required'],
            [['current_password'], function ($attribute) {
                if (!$this->user->validatePassword($this->$attribute)) {
                    $this->addError($attribute, Yii::t('app', 'Your current password is not correct.'));
                }
            }],
            [['new_password'], 'string', 'min' => Yii::$app->params['user.passwordMinLength'] ?? 8],
            [['confirm_password'], 'compare', 'compareAttribute' => 'new_password', 'message' => Yii::t('app', 'The passwords do not match.')],
        ];
    }

    public function attributeLabels()
    {
        return [
            'current_password' => Yii::t('app', 'Current password'),
            'new_password' => Yii::t('app', 'New password'),
            'confirm_password' => Yii::t('app', 'Repeat the new password'),
        ];
    }

    public function save()
    {
        if (!$this->validate()) {
            return false;
        }
        $this->user->setPassword($this->new_password);
        $this->user->generateAuthKey(); // signs out "remember me" logins on other devices

        return $this->user->save(false);
    }
}
