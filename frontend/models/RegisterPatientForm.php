<?php

namespace frontend\models;

use common\models\Consent;
use common\models\User;
use Yii;
use yii\base\Model;

/**
 * Front desk (or a consultant) registers a walk-in patient who has no account: name and phone,
 * email optional. The account is active straight away with a random password nobody knows; once it
 * has an email, the patient can take it over with "forgot password".
 *
 * If active patients already use the phone number, register() returns null and fills $matches,
 * so staff pick the existing patient instead of creating a duplicate (or confirm it is someone else).
 */
class RegisterPatientForm extends Model
{
    public $full_name;
    public $phone;
    public $email;
    /** @var bool register even though other patients use this phone (e.g. family members) */
    public $confirm_new = false;

    /** @var bool|string F11: the patient agreed to the privacy notice (verbally, to staff) */
    public $consent;

    /** @var bool|string|null F11: the patient wants WhatsApp/SMS reminders */
    public $reminders;

    /** @var string how the consent was given: Consent::METHOD_FRONT_DESK, or METHOD_BOOKING_LINK */
    public $consentMethod = Consent::METHOD_FRONT_DESK;

    /** @var User[] active patients already using the phone */
    public $matches = [];

    public function rules()
    {
        return [
            [['full_name', 'phone', 'email'], 'trim'],
            [['full_name', 'phone'], 'required'],
            [['full_name'], 'string', 'min' => 2, 'max' => 150],
            [['phone'], 'match', 'pattern' => '/^\+?[0-9][0-9 ]{8,16}$/', 'message' => Yii::t('app', 'Enter a phone number such as 0712 345 678 or +254 712 345 678.')],
            [['phone'], function ($attribute) {
                // user.phone_number holds 15 characters
                $digits = strlen(preg_replace('/\D/', '', $this->phone));
                if ($digits < 9 || strlen($this->getNormalizedPhone()) > 15) {
                    $this->addError($attribute, Yii::t('app', 'Enter a phone number such as 0712 345 678 or +254 712 345 678.'));
                }
            }, 'skipOnError' => true],
            [['email'], 'default', 'value' => null],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],
            [['email'], 'unique', 'targetClass' => User::class, 'message' => Yii::t('app', 'An account already uses this email. Look the patient up by email instead.')],
            [['confirm_new'], 'boolean'],
            // F11: never registered without consent; the reminders question needs an answer
            [['consent'], 'compare', 'compareValue' => 1, 'operator' => '==', 'skipOnEmpty' => false, 'when' => function () { return Consent::isEnabled(); }, 'message' => Yii::t('app', "Confirm the patient's consent first.")],
            [['reminders'], 'required', 'when' => function () { return Consent::isEnabled(); }, 'message' => Yii::t('app', 'Ask whether the patient wants WhatsApp/SMS reminders.')],
            [['reminders'], 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name'),
            'phone' => Yii::t('app', 'Phone'),
            'email' => Yii::t('app', 'Email (optional)'),
        ];
    }

    /** The number as stored: digits only, with a leading + kept. */
    public function getNormalizedPhone()
    {
        $phone = (string) $this->phone;
        return (strpos($phone, '+') === 0 ? '+' : '') . preg_replace('/\D/', '', $phone);
    }

    /**
     * @return User|null the new patient, or null when invalid or when $matches needs a decision
     */
    public function register()
    {
        if (!$this->validate()) {
            return null;
        }

        if (!$this->confirm_new) {
            $this->matches = User::find()
                ->where(['like', 'phone_number', '%' . substr(preg_replace('/\D/', '', $this->phone), -9), false])
                ->andWhere(['status' => User::STATUS_ACTIVE])
                ->andWhere(['or', ['role' => 'client'], ['role' => null], ['role' => '']])
                ->limit(10)
                ->all();
            if ($this->matches) {
                return null;
            }
        }

        $user = new User();
        $user->username = User::generateUniqueUsername(preg_replace('/[^a-z0-9]+/', '.', strtolower($this->full_name)));
        $user->full_name = $this->full_name;
        $user->phone_number = $this->getNormalizedPhone();
        $user->email = $this->email;
        $user->role = 'client';
        $user->status = User::STATUS_ACTIVE;
        // Nobody knows this password; the patient sets their own through "forgot password" once they have an email
        $user->setPassword(Yii::$app->security->generateRandomString(32));
        $user->generateAuthKey();

        if (!$user->save(false)) {
            $this->addError('full_name', Yii::t('app', 'Could not register the patient. Please try again.'));
            return null;
        }
        Yii::info("Walk-in patient #{$user->id} registered by user #" . Yii::$app->user->id, 'front-desk');
        $this->recordConsent($user->id);

        return $user;
    }

    /**
     * Records the consent given with this registration (also for an existing patient chosen instead).
     */
    public function recordConsent($userId)
    {
        if (!Consent::isEnabled()) {
            return;
        }
        $form = new ConsentForm(['data' => '1', 'reminders' => filter_var($this->reminders, FILTER_VALIDATE_BOOLEAN) ? '1' : '0']);
        $form->save($userId, $this->consentMethod);
    }
}
