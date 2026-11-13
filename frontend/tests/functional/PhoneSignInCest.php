<?php

namespace frontend\tests\functional;

use common\components\PhoneMessenger;
use common\models\LoginCode;
use common\models\User;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * Signing in with a phone number and a code, through the pages: number → code → account.
 */
class PhoneSignInCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        PhoneMessenger::$outbox = [];
        LoginCode::deleteAll();
    }

    private function codeSent()
    {
        preg_match('/^(\d{6}) /', PhoneMessenger::last()['text'], $m);

        return $m[1];
    }

    private function walkIn($username, $fullName)
    {
        $user = $this->createUser($username, 'client');
        $user->full_name = $fullName;
        $user->phone_number = '0700 111 222';
        $user->email = null;
        $user->save(false);

        return $user;
    }

    private function proveNumber(FunctionalTester $I, $phone = '0700111222')
    {
        $I->amOnRoute('phone-sign-in/index');
        $I->submitForm('#phone-form', ['phone' => $phone]);
        $I->see('Enter your code');
        $I->see('by WhatsApp');
        $I->see('+254 7•• ••• ' . substr(preg_replace('/\D/', '', $phone), -3));
        $I->submitForm('#code-form', ['code' => $this->codeSent(), 'remember' => '1']);
    }

    public function loginAndSignupOfferThePhone(FunctionalTester $I)
    {
        $I->amOnRoute('site/login');
        $I->seeElement('#phone-sign-in-link');
        $I->see('Sign in with your phone number');
        $I->see('or with your username and password');

        $I->amOnRoute('site/signup');
        $I->see('Sign up with your phone number');
        $I->see('or create an account with your email');
    }

    public function aFamilyMemberPicksTheirAccount(FunctionalTester $I)
    {
        $mary = $this->walkIn('mary.w', 'Mary Wanjiku');
        $john = $this->walkIn('john.w', 'John Wanjiku');

        $this->proveNumber($I);
        $I->see('Who is signing in?');
        $I->see('Mary W.');
        $I->see('John W.');
        $I->dontSee('Wanjiku', '#accounts');

        $I->submitForm('#accounts form:nth-of-type(1)', ['account' => $john->id]);
        $I->assertNotNull(User::findOne($john->id)->phone_verified_at);
        $I->assertNull(User::findOne($mary->id)->phone_verified_at);
        $I->amOnRoute('appointments/index');
        $I->dontSeeInCurrentUrl('login');
        $I->dontSeeInCurrentUrl('consent');
    }

    public function aNewNumberCreatesAnAccountThenAsksForConsent(FunctionalTester $I)
    {
        $this->proveNumber($I, '+254 700 333 444');
        $I->see('Create your account');
        $I->submitForm('#create-form', ['full_name' => 'Grace Achieng']);

        $user = User::findOne(['phone_key' => '254700333444']);
        $I->assertNotNull($user);
        $I->assertSame('Grace Achieng', $user->full_name);
        $I->assertSame('client', $user->role);
        $I->seeInCurrentUrl('consent');
    }

    public function aWrongCodeIsRefused(FunctionalTester $I)
    {
        $I->amOnRoute('phone-sign-in/index');
        $I->submitForm('#phone-form', ['phone' => '0700111222']);
        $code = $this->codeSent();
        $I->submitForm('#code-form', ['code' => $code === '000000' ? '111111' : '000000']);
        $I->see('That code is not right');

        $I->amOnRoute('phone-sign-in/choose');
        $I->seeInCurrentUrl('phone-sign-in');
        $I->dontSeeElement('#create-form');
    }

    public function anUnreadableNumberIsExplained(FunctionalTester $I)
    {
        $I->amOnRoute('phone-sign-in/index');
        $I->submitForm('#phone-form', ['phone' => '12345']);
        $I->see('Enter a phone number such as 0712 345 678');
        $I->assertSame([], PhoneMessenger::$outbox);
    }

    public function staffAreNeverOffered(FunctionalTester $I)
    {
        $staff = $this->createUser('desk.staff', 'front_desk');
        $staff->phone_number = '0700111222';
        $staff->email = null;
        $staff->save(false);

        $this->proveNumber($I);
        $I->see('Create your account');
        $I->dontSeeElement('#accounts');
    }

    public function aSelfRegisteredAccountIsLinkedBySigningInWithEmail(FunctionalTester $I)
    {
        $patient = $this->createUser('self.reg', 'client');
        $patient->phone_number = '0700111222';
        $patient->save(false);

        $this->proveNumber($I);
        $I->seeElement('#unlinked-note');
        $I->click('sign in with your email', '#unlinked-note');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'self.reg', 'LoginForm[password]' => 'password_0']);

        $I->assertNotNull(User::findOne($patient->id)->phone_verified_at);
    }

    public function resendWaitsAMinuteAndOnlyByPost(FunctionalTester $I)
    {
        $I->amOnRoute('phone-sign-in/index');
        $I->submitForm('#phone-form', ['phone' => '0700111222']);
        $I->see('ask for another in');
        $I->submitForm('#resend-other-form', []);
        $I->see('Please wait a minute');
        $I->assertCount(1, PhoneMessenger::$outbox);

        LoginCode::updateAll(['created_at' => time() - 120]);
        $I->amOnRoute('phone-sign-in/resend');
        $I->seeResponseCodeIs(405);
    }

    public function signedInUsersAreSentHome(FunctionalTester $I)
    {
        $this->createParticipants();
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('phone-sign-in/index');
        $I->dontSeeElement('#phone-form');
    }
}
