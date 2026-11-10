<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class AccountCest
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
    }

    public function frontDesksProfileLinkOpensTheirAccount(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('site/index');
        $I->seeLink('Profile', '/index-test.php/account/index');

        $I->amOnRoute('account/index');
        $I->seeResponseCodeIs(200);
        $I->see('front.desk', 'dd');
        $I->see('Front desk', 'dd');
        $I->see('Dr. Test', 'dd');
    }

    public function consultantsAndPatientsKeepTheirProfileLinks(FunctionalTester $I)
    {
        foreach ([$this->consultantUser, $this->client] as $user) {
            $I->amLoggedInAs($user);
            $I->amOnRoute('site/index');
            $I->seeLink('Profile', '/index-test.php/consultant/view?id=' . $user->id);
        }
    }

    public function theOldProfileUrlNoLongerCrashesForStaff(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('consultant/view', ['id' => $this->frontDesk->id + 1000]);
        $I->seeResponseCodeIs(404);
    }

    public function staffUpdateTheirDetails(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('account/index');
        $I->submitForm('#account-form', ['AccountForm[full_name]' => 'Wanjiru Kamau', 'AccountForm[phone]' => '0711222333']);

        $I->see('Your details are saved.');
        $user = User::findOne($this->frontDesk->id);
        $I->assertSame('Wanjiru Kamau', $user->full_name);
        $I->assertSame('0711222333', $user->phone_number);
    }

    public function staffChangeTheirPassword(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('account/index');
        $I->submitForm('#password-form', [
            'ChangePasswordForm[current_password]' => 'wrong-one',
            'ChangePasswordForm[new_password]' => 'New-secret-9',
            'ChangePasswordForm[confirm_password]' => 'New-secret-9',
        ]);
        $I->see('Your current password is not correct.');

        $I->submitForm('#password-form', [
            'ChangePasswordForm[current_password]' => 'password_0',
            'ChangePasswordForm[new_password]' => 'New-secret-9',
            'ChangePasswordForm[confirm_password]' => 'New-secret-9',
        ]);
        $I->see('Your password is changed.');
        $I->assertTrue(User::findOne($this->frontDesk->id)->validatePassword('New-secret-9'));
    }
}
