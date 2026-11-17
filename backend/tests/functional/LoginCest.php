<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\fixtures\UserFixture;
use common\models\User;

/**
 * Signing in to the admin area: platform admins only, with the same username and password as on
 * the frontend.
 */
class LoginCest
{
    /**
     * Load fixtures before db transaction begin
     * @see \Codeception\Module\Yii2::loadFixtures()
     */
    public function _fixtures()
    {
        return [
            'user' => [
                'class' => UserFixture::class,
                'dataFile' => codecept_data_dir() . 'login_data.php',
            ],
        ];
    }

    private function signIn(FunctionalTester $I, $username)
    {
        $I->amOnRoute('/site/login');
        $I->fillField('Username', $username);
        $I->fillField('Password', 'password_0');
        $I->click('login-button');
    }

    public function aPlatformAdminSignsIn(FunctionalTester $I)
    {
        $admin = new User(['username' => 'platform.admin', 'email' => 'platform.admin@example.com', 'role' => 'admin', 'status' => User::STATUS_ACTIVE]);
        $admin->setPassword('password_0');
        $admin->generateAuthKey();
        $admin->save(false);

        $this->signIn($I, 'platform.admin');

        $I->see('Logout (platform.admin)', 'form button[type=submit]');
        $I->see('Waiting for you');
        $I->seeLink('Refunds');
    }

    public function anyoneElseIsRefusedAsIfTheirPasswordWereWrong(FunctionalTester $I)
    {
        // A patient account from the fixture, with the right password
        $this->signIn($I, 'erau');

        $I->see('Incorrect username or password.');
        $I->dontSee('Waiting for you');
    }

    public function guestsAreSentToSignIn(FunctionalTester $I)
    {
        $I->amOnRoute('/refund/index');
        $I->seeInCurrentUrl('login');
    }
}
