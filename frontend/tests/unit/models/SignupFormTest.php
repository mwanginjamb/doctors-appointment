<?php

namespace frontend\tests\unit\models;

use common\fixtures\UserFixture;
use frontend\models\SignupForm;

class SignupFormTest extends \Codeception\Test\Unit
{
    /**
     * @var \frontend\tests\UnitTester
     */
    protected $tester;


    public function _before()
    {
        $this->tester->haveFixtures([
            'user' => [
                'class' => UserFixture::class,
                'dataFile' => codecept_data_dir() . 'user.php'
            ]
        ]);
    }

    public function testCorrectSignup()
    {
        $model = new SignupForm([
            'username' => 'some_username',
            'email' => 'some_email@example.com',
            'password' => 'some_password',
            'full_name' => 'Some Name',
            'phone_number' => '0712345678',
        ]);

        $user = $model->signup();
        verify($user)->notEmpty();

        /** @var \common\models\User $user */
        $user = $this->tester->grabRecord('common\models\User', [
            'username' => 'some_username',
            'email' => 'some_email@example.com',
            'status' => \common\models\User::STATUS_INACTIVE
        ]);

        $this->tester->seeEmailIsSent();

        $mail = $this->tester->grabLastSentEmail();

        verify($mail)->instanceOf('yii\mail\MessageInterface');
        verify($mail->getTo())->arrayHasKey('some_email@example.com');
        verify($mail->getFrom())->arrayHasKey(\Yii::$app->params['supportEmail']);
        verify($mail->getSubject())->equals('Account registration at ' . \Yii::$app->name);
        verify($mail->toString())->stringContainsString($user->verification_token);
    }

    /**
     * Regression: if the verification email cannot be sent, the account must not
     * be left behind (it used to be, and a retry then failed as "already taken").
     */
    public function testMailFailureRollsBackAccount()
    {
        $original = \Yii::$app->mailer;
        \Yii::$app->set('mailer', new class (['viewPath' => '@common/mail']) extends \common\components\ObservableMailer {
            protected function sendMessage($message): bool
            {
                $this->lastError = ['type' => 'auth', 'message' => '535 5.7.139 Authentication unsuccessful', 'exception' => 'test', 'code' => 535, 'smtp_debug' => ''];
                return false;
            }
        });

        try {
            $model = new SignupForm([
                'username' => 'mail_fail_user',
                'email' => 'mail_fail@example.com',
                'password' => 'some_password',
                'confirmPassword' => 'some_password',
                'full_name' => 'Mail Fail',
                'phone_number' => '0712345678',
                'role' => 'client',
            ]);

            verify($model->signup())->false();
            verify($model->failureReason)->equals(SignupForm::FAILURE_MAIL);
            verify($model->mailError['type'])->equals('auth');
            $this->tester->dontSeeRecord('common\models\User', ['username' => 'mail_fail_user']);
        } finally {
            \Yii::$app->set('mailer', $original);
        }
    }

    public function testNotCorrectSignup()
    {
        $model = new SignupForm([
            'username' => 'troy.becker',
            'email' => 'nicolas.dianna@hotmail.com',
            'password' => 'some_password',
        ]);

        verify($model->signup())->empty();
        verify($model->getErrors('username'))->notEmpty();
        verify($model->getErrors('email'))->notEmpty();

        verify($model->getFirstError('username'))
            ->equals('This username has already been taken.');
        verify($model->getFirstError('email'))
            ->equals('This email address has already been taken.');
    }
}
