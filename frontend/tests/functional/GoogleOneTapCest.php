<?php

namespace frontend\tests\functional;

use common\authclient\GoogleIdToken;
use common\models\User;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use frontend\tests\GoogleTokenTrait;

/**
 * Google One Tap: the prompt on the login and signup pages, and signing in with its token.
 */
class GoogleOneTapCest
{
    use BookingDataTrait;
    use GoogleTokenTrait;

    public function _before(FunctionalTester $I)
    {
        $this->trustTestGoogleKey();
    }

    public function _after(FunctionalTester $I)
    {
        GoogleIdToken::$keySet = null;
    }

    private function postToken(FunctionalTester $I, $token)
    {
        $I->amOnRoute('site/login');
        $I->submitForm('#google-one-tap-form', ['credential' => $token]);
    }

    public function loginAndSignupOfferOneTap(FunctionalTester $I)
    {
        $I->amOnRoute('site/login');
        $I->seeElement('#g_id_onload', ['data-client_id' => $this->googleClientId(), 'data-context' => 'signin']);
        $I->seeInSource('https://accounts.google.com/gsi/client');
        $I->seeElement('#google-one-tap-form input[name="_csrf-frontend"]');

        $I->amOnRoute('site/signup');
        $I->seeElement('#g_id_onload', ['data-context' => 'signup']);
    }

    public function aNewGoogleUserBecomesAPatient(FunctionalTester $I)
    {
        $this->postToken($I, $this->googleToken());

        $user = User::findOne(['email' => 'grace.onetap@gmail.com']);
        $I->assertNotNull($user);
        $I->assertSame('google', $user->auth_provider);
        $I->assertSame('110248495921238986420', $user->auth_client_id);
        $I->assertSame('client', $user->role);
        $I->seeInCurrentUrl('consent'); // a new patient agrees to the privacy notice first
    }

    public function anAccountLinkedWithTheGoogleButtonIsTheSameAccount(FunctionalTester $I)
    {
        $existing = User::createOrUpdateFromOauth('google', '110248495921238986420', 'grace.onetap@gmail.com', 'Grace', true);

        $this->postToken($I, $this->googleToken());
        $I->assertSame(1, (int) User::find()->where(['email' => 'grace.onetap@gmail.com'])->count());
        $I->dontSee('We could not sign you in');
        $I->amOnRoute('site/login');
        $I->dontSeeElement('#login-form'); // signed in: sent home
    }

    public function anUnverifiedEmailDoesNotTakeOverAnAccount(FunctionalTester $I)
    {
        $staff = $this->createUser('desk.person', 'front_desk');

        $this->postToken($I, $this->googleToken(['sub' => '999', 'email' => $staff->email, 'email_verified' => false]));
        $I->see('We could not sign you in with Google');
        $I->assertNull(User::findOne($staff->id)->auth_provider);
    }

    public function aForgedTokenIsRefused(FunctionalTester $I)
    {

        $this->postToken($I, $this->googleToken([], $this->otherKey()));
        $I->see('We could not sign you in with Google');
        $I->assertNull(User::findOne(['email' => 'grace.onetap@gmail.com']));
    }

    public function onlyByPostWithOurCsrfToken(FunctionalTester $I)
    {
        $I->amOnRoute('google-one-tap/sign-in');
        $I->seeResponseCodeIs(405);
    }
}
