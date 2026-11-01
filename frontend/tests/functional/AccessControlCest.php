<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\Gender;
use frontend\models\UserProfile;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\helpers\Url;

class AccessControlCest
{
    use BookingDataTrait;

    /** @var Appointments */
    private $appointment;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->appointment = $this->book('10:00');
    }

    public function guestIsSentToLoginForPatientProfiles(FunctionalTester $I)
    {
        $I->amOnRoute('user-profile/index');
        $I->seeInCurrentUrl('site/login');
    }

    public function guestCannotBookThroughApi(FunctionalTester $I)
    {
        $before = Appointments::find()->count();

        $I->sendAjaxPostRequest(Url::to(['/api/visit']), ['date' => $this->date, 'time' => '11:00:00']);

        $I->dontSeeResponseCodeIs(200);
        $I->assertEquals($before, Appointments::find()->count());
    }

    public function patientCanViewOwnAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->seeResponseCodeIs(200);
        $I->seeInSource('value="Headache"');
    }

    public function patientCannotViewSomeoneElsesAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->otherClient);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->seeResponseCodeIs(404);
    }

    public function patientCannotConfirmAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/appointments/confirm', 'id' => $this->appointment->id]);
        $I->seeResponseCodeIs(403);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->appointment->id)->status);
    }

    public function patientCannotViewSomeoneElsesProfile(FunctionalTester $I)
    {
        $profile = UserProfile::findOne(['user_id' => $this->client->id]);

        $I->amLoggedInAs($this->otherClient);
        $I->amOnRoute('user-profile/view', ['id' => $profile->id]);
        $I->seeResponseCodeIs(404);
    }

    public function consultantCanViewTheirPatientsProfile(FunctionalTester $I)
    {
        $profile = UserProfile::findOne(['user_id' => $this->client->id]);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('user-profile/view', ['id' => $profile->id]);
        $I->seeResponseCodeIs(200);
    }

    public function consultantCannotSeeOtherPatientsProfile(FunctionalTester $I)
    {
        $profile = UserProfile::findOne(['user_id' => $this->otherClient->id]);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('user-profile/view', ['id' => $profile->id]);
        $I->seeResponseCodeIs(404);
    }

    public function consultantCannotEditAnotherConsultantsProfile(FunctionalTester $I)
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');

        $I->amLoggedInAs($otherDoctor);
        $I->amOnRoute('consultant/update', ['id' => $this->consultant->id]);
        $I->seeResponseCodeIs(404);
    }

    public function nonAdminCannotManageLookups(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('gender/index');
        $I->seeResponseCodeIs(403);
    }

    public function adminCanManageLookups(FunctionalTester $I)
    {
        $I->haveRecord(Gender::class, ['name' => 'Female']);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('gender/index');
        $I->seeResponseCodeIs(200);
        $I->see('Female');
    }

    public function consultantDetailsAreHtmlEncoded(FunctionalTester $I)
    {
        $this->consultant->names = '<script>alert(1)</script>';
        $this->consultant->save(false);

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('consultant/view', ['id' => $this->consultant->id, 'consultant' => 1]);
        $I->seeResponseCodeIs(200);
        $I->dontSeeInSource('<script>alert(1)</script>');
        $I->seeInSource('&lt;script&gt;alert(1)&lt;/script&gt;');
    }
}
