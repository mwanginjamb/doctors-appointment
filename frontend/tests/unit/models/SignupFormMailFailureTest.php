<?php

namespace frontend\tests\unit\models;

use common\components\ObservableMailer;
use frontend\models\SignupForm;

/**
 * Regression: if the verification email cannot be sent, the account must not be
 * left behind (it used to be, and a retry then failed as "already taken").
 *
 * Kept in its own class WITHOUT fixtures on purpose: loading fixtures runs
 * TRUNCATE, which implicitly commits the transaction Codeception wraps each test
 * in. Yii still believes that transaction is open, so signup()'s nested
 * transaction becomes a no-op savepoint, the INSERT autocommits, and the
 * rollback cannot be observed. Production has no outer transaction, so this
 * only affects tests.
 */
class SignupFormMailFailureTest extends \Codeception\Test\Unit
{
    /** @var \frontend\tests\UnitTester */
    protected $tester;

    private $originalMailer;

    protected function _before()
    {
        $this->originalMailer = \Yii::$app->mailer;
        \Yii::$app->set('mailer', new class (['viewPath' => '@common/mail']) extends ObservableMailer {
            protected function sendMessage($message): bool
            {
                $this->lastError = [
                    'type' => 'auth',
                    'message' => '535 5.7.139 Authentication unsuccessful',
                    'exception' => 'test',
                    'code' => 535,
                    'smtp_debug' => '',
                ];
                return false;
            }
        });
    }

    protected function _after()
    {
        \Yii::$app->set('mailer', $this->originalMailer);
    }

    public function testMailFailureRollsBackAccount()
    {
        $db = \Yii::$app->db;
        $db->open();
        // Guard: the assertion below is only meaningful inside a real transaction.
        verify($db->pdo->inTransaction())->true();

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
    }
}
