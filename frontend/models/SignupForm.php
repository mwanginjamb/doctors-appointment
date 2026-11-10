<?php

namespace frontend\models;

use Yii;
use yii\base\Model;
use common\models\User;
use common\components\ObservableMailer;
use common\components\RequestContext;

/**
 * Signup form
 */
class SignupForm extends Model
{
    public $username;
    public $email;
    public $password;
    public $confirmPassword;

    public $full_name;

    public $phone_number;

    public $role;

    /** @var string|null F11: agrees to the privacy notice (patients only) */
    public $consent_data;

    /** @var string|null F11: WhatsApp/SMS reminders, '1' or '0' (patients only) */
    public $consent_reminders;

    public const LOG_CATEGORY = 'signup';

    public const FAILURE_VALIDATION = 'validation';
    public const FAILURE_PERSIST = 'persist';
    public const FAILURE_MAIL = 'mail';
    public const FAILURE_EXCEPTION = 'exception';

    /** @var string|null which stage the last signup() failed at (FAILURE_* constant) */
    public $failureReason;

    /** @var array|null classified SMTP error from ObservableMailer when failureReason is "mail" */
    public $mailError;

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            ['username', 'trim'],
            ['username', 'required'],
            ['username', 'unique', 'targetClass' => '\common\models\User', 'message' => 'This username has already been taken.'],
            ['username', 'string', 'min' => 2, 'max' => 255],

            ['email', 'trim'],
            ['email', 'required'],
            ['email', 'email'],
            ['email', 'string', 'max' => 255],
            ['email', 'unique', 'targetClass' => '\common\models\User', 'message' => 'This email address has already been taken.'],

            ['password', 'required'],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength']],
            ['confirmPassword', 'compare', 'compareAttribute' => 'password', 'message' => 'Passwords do not match, try again'],

            [['full_name', 'phone_number'], 'required'],

            ['role', 'default', 'value' => 'client'],
            ['role', 'in', 'range' => ['client', 'consultant'], 'message' => 'Please select a valid role'],

            // F11: patients agree to the privacy notice and answer the reminders question
            ['consent_data', 'required', 'requiredValue' => '1', 'when' => [$this, 'asksConsent'], 'message' => 'Please agree to the privacy notice to sign up.'],
            ['consent_reminders', 'required', 'when' => [$this, 'asksConsent'], 'message' => 'Choose whether you want WhatsApp/SMS reminders.'],
            ['consent_reminders', 'in', 'range' => ['0', '1']],

        ];
    }

    /**
     * Signs user up.
     *
     * The account is created inside a transaction and only committed once the
     * verification email has been handed to the SMTP server. Previously the user
     * row was saved first and the email failure ignored, leaving an INACTIVE
     * account the person could never verify, and a retry then failed with
     * "username/email already taken".
     *
     * After a failed call, {@see $failureReason} says which stage failed and
     * {@see $mailError} holds the classified SMTP error when it was the email.
     *
     * @return bool whether the account was created and the verification email sent
     */
    public function signup()
    {
        $this->failureReason = null;
        $this->mailError = null;
        $log = [
            'role' => $this->role,
            'email' => RequestContext::maskEmail($this->email),
        ];

        if (!$this->validate()) {
            $this->failureReason = self::FAILURE_VALIDATION;
            // Field names and messages only; never the submitted values.
            Yii::info(['event' => 'signup.validation_failed', 'errors' => $this->getFirstErrors()] + $log, self::LOG_CATEGORY);
            return false;
        }

        $user = new User();
        $user->username = $this->username;
        $user->email = $this->email;
        $user->setPassword($this->password);
        $user->generateAuthKey();
        $user->generateEmailVerificationToken();

        $user->full_name = $this->full_name;
        $user->phone_number = $this->phone_number;
        $user->role = $this->role;

        $transaction = User::getDb()->beginTransaction();
        try {
            if (!$user->save()) {
                $transaction->rollBack();
                $this->failureReason = self::FAILURE_PERSIST;
                Yii::error(['event' => 'signup.save_failed', 'errors' => $user->getFirstErrors()] + $log, self::LOG_CATEGORY);
                return false;
            }

            if ($this->asksConsent()) {
                $consent = new ConsentForm(['data' => $this->consent_data, 'reminders' => (string) $this->consent_reminders]);
                $consent->save($user->id, \common\models\Consent::METHOD_ONLINE);
            }

            if (!$this->sendEmail($user)) {
                $transaction->rollBack();
                $this->failureReason = self::FAILURE_MAIL;
                $mailer = Yii::$app->mailer;
                $this->mailError = $mailer instanceof ObservableMailer ? $mailer->lastError : null;
                Yii::error([
                    'event' => 'signup.mail_failed',
                    'diagnosis' => 'SMTP/transport problem, not signup logic; account rolled back',
                    'smtp_error_type' => $this->mailError['type'] ?? 'unknown',
                    'smtp_error' => $this->mailError['message'] ?? '(see mail log)',
                ] + $log, self::LOG_CATEGORY);
                return false;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            $this->failureReason = self::FAILURE_EXCEPTION;
            Yii::error([
                'event' => 'signup.exception',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ] + $log, self::LOG_CATEGORY);
            throw $e;
        }

        Yii::info(['event' => 'signup.succeeded', 'user_id' => $user->id] + $log, self::LOG_CATEGORY);
        return true;
    }

    /** Patients (not consultants) are asked for consent. */
    public function isPatient()
    {
        return ($this->role ?: 'client') === 'client';
    }

    /** Patients are asked for consent while consent recording is on (CONSENT_ENABLED). */
    public function asksConsent()
    {
        return $this->isPatient() && \common\models\Consent::isEnabled();
    }

    public function attributeLabels()
    {
        return [
            'consent_data' => (new ConsentForm())->getAttributeLabel('data'),
            'consent_reminders' => (new ConsentForm())->getAttributeLabel('reminders'),
        ];
    }

    /**
     * Sends confirmation email to user
     * @param User $user user model to with email should be send
     * @return bool whether the email was sent
     */
    protected function sendEmail($user)
    {
        return Yii::$app
            ->mailer
            ->compose(
                ['html' => 'emailVerify-html', 'text' => 'emailVerify-text'],
                ['user' => $user]
            )
            ->setFrom([Yii::$app->params['supportEmail'] => Yii::$app->name . ' robot'])
            ->setTo($this->email)
            ->setSubject('Account registration at ' . Yii::$app->name)
            ->send();
    }
}
