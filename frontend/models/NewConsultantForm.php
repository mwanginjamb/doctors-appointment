<?php

namespace frontend\models;

use common\models\User;
use Yii;
use yii\base\Model;

/**
 * Admin form that creates a doctor: their sign-in account plus a basic consultant profile with the
 * default working week. The admin (or the doctor, once signed in) completes the profile afterwards.
 * The doctor signs in with their email address as the username.
 */
class NewConsultantForm extends Model
{
    public $full_name;
    public $email;
    public $phone;
    public $practice_name;
    public $password;

    /** @var Consultant|null the profile created by save() */
    public $consultant;

    public function rules()
    {
        return [
            [['full_name', 'email', 'phone', 'practice_name'], 'trim'],
            [['full_name', 'email', 'phone', 'password'], 'required'],
            [['full_name'], 'string', 'max' => 150],
            [['email'], 'email'],
            [['email'], 'unique', 'targetClass' => User::class],
            [['email'], 'unique', 'targetClass' => User::class, 'targetAttribute' => 'username', 'message' => Yii::t('app', 'An account already uses this email.')],
            [['phone'], 'string', 'max' => 15],
            [['practice_name'], 'string', 'max' => 255],
            [['password'], 'string', 'min' => Yii::$app->params['user.passwordMinLength'] ?? 8],
        ];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name, as patients see it (e.g. Dr. Jane Mwangi)'),
            'email' => Yii::t('app', 'Email (they sign in with it)'),
            'phone' => Yii::t('app', 'Phone'),
            'practice_name' => Yii::t('app', 'Practice name (optional)'),
            'password' => Yii::t('app', 'Initial password'),
        ];
    }

    public function save()
    {
        if (!$this->validate()) {
            return false;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $user = new User();
            $user->username = $this->email;
            $user->email = $this->email;
            $user->full_name = $this->full_name;
            $user->phone_number = $this->phone;
            $user->role = 'consultant';
            $user->status = User::STATUS_ACTIVE;
            $user->setPassword($this->password);
            $user->generateAuthKey();
            if (!$user->save(false)) {
                throw new \RuntimeException('Could not create the account.');
            }

            // Saved without the full profile rules: the rest of the profile is filled in afterwards.
            // afterSave() gives the doctor the default working week.
            $this->consultant = new Consultant([
                'user_id' => $user->id,
                'names' => $this->full_name,
                'consultant_email' => $this->email,
                'consultant_phone_number' => $this->phone,
                'practice_name' => $this->practice_name ?: null,
            ]);
            if (!$this->consultant->save(false)) {
                throw new \RuntimeException('Could not create the consultant profile.');
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }
}
