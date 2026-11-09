<?php

namespace frontend\tests\functional;

use common\fixtures\UserFixture;
use frontend\tests\FunctionalTester;

class VerifyEmailCest
{
    /**
     * Load fixtures before db transaction begin
     * Called in _before()
     * @see \Codeception\Module\Yii2::_before()
     * @see \Codeception\Module\Yii2::loadFixtures()
     * @return array
     */
    public function _fixtures()
    {
        return [
            'user' => [
                'class' => UserFixture::class,
                'dataFile' => codecept_data_dir() . 'user.php',
            ],
        ];
    }

    public function checkEmptyToken(FunctionalTester $I)
    {
        $I->amOnRoute('site/verify-email', ['token' => '']);
        $I->canSee('Bad Request', 'h1');
        $I->canSee('Verify email token cannot be blank.');
    }

    public function checkInvalidToken(FunctionalTester $I)
    {
        $I->amOnRoute('site/verify-email', ['token' => 'wrong_token']);
        $I->canSee('Bad Request', 'h1');
        $I->canSee('This verification link is invalid or has already been used.');
    }

    public function checkNoToken(FunctionalTester $I)
    {
        $I->amOnRoute('site/verify-email');
        $I->canSee('Bad Request', 'h1');
        $I->canSee('Missing required parameters: token');
    }

    public function checkAlreadyActivatedToken(FunctionalTester $I)
    {
        $I->amOnRoute('site/verify-email', ['token' => 'already_used_token_1548675330']);
        $I->canSee('Bad Request', 'h1');
        $I->canSee('This verification link is invalid or has already been used.');
    }

    public function checkGetDoesNotActivate(FunctionalTester $I)
    {
        // Simulates an email link scanner / prefetcher following the link.
        $I->amOnRoute('site/verify-email', ['token' => '4ch0qbfhvWwkcuWqjN8SWRq72SOw1KYT_1548675330']);
        $I->amOnRoute('site/verify-email', ['token' => '4ch0qbfhvWwkcuWqjN8SWRq72SOw1KYT_1548675330']);
        $I->see('Confirm email', 'button');
        $I->seeRecord('common\models\User', [
            'username' => 'test.test',
            'status' => \common\models\User::STATUS_INACTIVE,
        ]);
    }

    public function checkSuccessVerification(FunctionalTester $I)
    {
        $I->amOnRoute('site/verify-email', ['token' => '4ch0qbfhvWwkcuWqjN8SWRq72SOw1KYT_1548675330']);
        $I->click('Confirm email');
        $I->canSee('Your email has been confirmed!');
        $I->see('Logout', 'form button[type=submit]');

        $I->seeRecord('common\models\User', [
           'username' => 'test.test',
           'email' => 'test@mail.com',
           'status' => \common\models\User::STATUS_ACTIVE
        ]);
    }
}
