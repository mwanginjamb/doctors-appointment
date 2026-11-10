<?php

namespace frontend\tests\functional;

use common\models\AccountClosureRequest;
use common\models\Consent;
use common\models\User;
use frontend\models\Appointments;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F11: the privacy notice, the consent page patients are held on, Privacy & messages, withdrawing
 * consent, and staff recording verbal consent.
 */
class ConsentCest
{
    use BookingDataTrait;

    /** @var User a patient with no consent recorded, like those from before F11 */
    private $legacy;

    public function _before(FunctionalTester $I)
    {
        \Yii::$app->cache->flush();
        $this->createParticipants();
        // createUser() records consent for patients; this one is created as staff and turned into a patient
        $this->legacy = $this->createUser('legacy.patient', 'consultant');
        $this->legacy->updateAttributes(['role' => 'client']);
    }

    public function thePrivacyNoticeIsPublic(FunctionalTester $I)
    {
        $I->amOnRoute('site/privacy');
        $I->seeResponseCodeIs(200);
        $I->see('Privacy notice', 'h1');
        $I->see('Version ' . Consent::noticeVersion());
        $I->see('to be provided'); // controller details come from .env
    }

    public function patientsWithoutConsentAreHeldOnTheConsentPage(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->legacy);
        $I->amOnRoute('appointments/index');
        $I->seeInCurrentUrl('consent');
        $I->see('Your privacy', 'h1');

        $I->submitForm('#consent-form', ['ConsentForm[data]' => '0', 'ConsentForm[reminders]' => '1']);
        $I->see('Please agree to the privacy notice to continue.');
        $I->assertSame('none', Consent::status($this->legacy->id));

        $I->submitForm('#consent-form', ['ConsentForm[data]' => '1', 'ConsentForm[reminders]' => '0']);
        $I->assertSame('given', Consent::status($this->legacy->id));
        $I->assertFalse(Consent::remindersAllowed($this->legacy->id));
        $I->assertSame(Consent::METHOD_ONLINE, Consent::latest($this->legacy->id, Consent::PURPOSE_DATA)->method);

        $I->amOnRoute('appointments/index');
        $I->dontSeeInCurrentUrl('consent');
    }

    public function staffAreNotHeld(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/index');
        $I->dontSeeInCurrentUrl('consent');
        $I->amOnRoute('consent/index');
        $I->seeResponseCodeIs(403);
    }

    public function patientsSwitchWhatsappRemindersOff(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('consent/settings');
        $I->see('On.', '#reminders-status');

        $I->submitForm('#reminders-form', []);
        $I->see('WhatsApp/SMS reminders are off.');
        $I->see('Off.', '#reminders-status');
        $I->assertFalse(Consent::remindersAllowed($this->client->id));
    }

    public function withdrawingConsentCancelsAppointmentsAndAsksAdminsToCloseTheAccount(FunctionalTester $I)
    {
        $appointment = $this->book('10:00');

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('consent/settings');
        $I->submitForm('#withdraw-form', ['reason' => 'Moving abroad']);

        $I->see('the clinic has been asked to close your account');
        $I->seeElement('#closure-pending');
        $I->assertSame('withdrawn', Consent::status($this->client->id));
        $I->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($appointment->id)->status);
        $request = AccountClosureRequest::openFor($this->client->id);
        $I->assertSame('Moving abroad', $request->reason);

        // Held on the consent page from now on
        $I->amOnRoute('appointments/index');
        $I->seeInCurrentUrl('consent');

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('closure-request/index');
        $I->see('Moving abroad');
        $I->submitForm('.closure-close-form', ['note' => 'Confirmed by phone']);
        $I->see('account is deactivated and the request is closed');
        $I->assertSame(User::STATUS_DELETED, (int) User::findOne($this->client->id)->status);
        $I->assertSame(AccountClosureRequest::STATUS_CLOSED, AccountClosureRequest::findOne($request->id)->status);
    }

    public function consentingAgainWithdrawsTheClosureRequest(FunctionalTester $I)
    {
        Consent::record($this->client->id, Consent::PURPOSE_DATA, false, Consent::METHOD_ONLINE);
        $request = AccountClosureRequest::open($this->client->id);

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('consent/index');
        $I->seeElement('#closure-pending');
        $I->submitForm('#consent-form', ['ConsentForm[data]' => '1', 'ConsentForm[reminders]' => '1']);

        $I->assertSame(AccountClosureRequest::STATUS_WITHDRAWN, AccountClosureRequest::findOne($request->id)->status);
        $I->assertSame('given', Consent::status($this->client->id));
    }

    public function staffRecordVerbalConsentWhenBookingAPatientWithoutIt(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/calendar'); // a page with the CSRF token for the API calls

        $refused = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '14:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $this->legacy->id,
        ]);
        $I->assertSame('error', $refused['status']);
        $I->assertTrue($refused['needsConsent']);
        $I->assertFalse(Appointments::find()->where(['patient_id' => $this->legacy->id])->exists());

        $booked = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '14:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $this->legacy->id,
            'consent' => true, 'reminders' => '1',
        ]);
        $I->assertSame('success', $booked['status'], json_encode($booked));
        $data = Consent::latest($this->legacy->id, Consent::PURPOSE_DATA);
        $I->assertSame(Consent::METHOD_FRONT_DESK, $data->method);
        $I->assertSame((int) $this->consultantUser->id, (int) $data->recorded_by);
    }

    public function patientLookupShowsWhetherConsentIsRecorded(FunctionalTester $I)
    {
        $this->legacy->updateAttributes(['email' => 'legacy@example.com']);
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('api/find-patient', ['q' => 'legacy@example.com']);
        $response = json_decode($I->grabPageSource(), true);

        $I->assertSame('success', $response['status']);
        $I->assertFalse($response['patients'][0]['consent']);
        $I->assertSame('Consent not recorded', $response['patients'][0]['consentStatus']);
    }

    public function signUpAsksForConsent(FunctionalTester $I)
    {
        $I->amOnRoute('site/signup');
        $I->postAsLink(['/site/signup'], ['role' => 'client']);
        $I->submitForm('#form-signup', [
            'SignupForm[username]' => 'new.patient',
            'SignupForm[email]' => 'new.patient@example.com',
            'SignupForm[full_name]' => 'New Patient',
            'SignupForm[phone_number]' => '0712345678',
            'SignupForm[password]' => 'tester_password',
            'SignupForm[confirmPassword]' => 'tester_password',
        ]);
        $I->see('Please agree to the privacy notice to sign up.');
        $I->dontSeeRecord(User::class, ['username' => 'new.patient']);

        $I->submitForm('#form-signup', [
            'SignupForm[username]' => 'new.patient',
            'SignupForm[email]' => 'new.patient@example.com',
            'SignupForm[full_name]' => 'New Patient',
            'SignupForm[phone_number]' => '0712345678',
            'SignupForm[password]' => 'tester_password',
            'SignupForm[confirmPassword]' => 'tester_password',
            'SignupForm[consent_data]' => '1',
            'SignupForm[consent_reminders]' => '1',
        ]);
        $user = User::findOne(['username' => 'new.patient']);
        $I->assertNotNull($user);
        $I->assertSame('given', Consent::status($user->id));
        $I->assertTrue(Consent::remindersAllowed($user->id));
    }
}
