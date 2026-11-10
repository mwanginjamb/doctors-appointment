<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantTimeOff;
use frontend\models\ConsultantWorkingHours;
use frontend\models\SearchForm;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class ConsultantManagementCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    public function onlyAdminsManageConsultants(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('manage-consultants/index');
        $I->seeResponseCodeIs(403);

        $I->sendCsrfPost(['/site/deactivate-user', 'id' => $this->client->id]);
        $I->seeResponseCodeIs(403);
        $I->assertSame(User::STATUS_ACTIVE, (int) User::findOne($this->client->id)->status);
    }

    public function adminSeesEveryConsultant(FunctionalTester $I)
    {
        $this->book('10:00');
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('manage-consultants/index');

        $I->see('Dr. Test', 'table');
        $I->see('Active', 'table');
        $I->see('Mon–Sat 08:00–13:00, 14:00–17:00', 'table');
    }

    public function adminAddsAConsultant(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('manage-consultants/create');
        $I->submitForm('#new-consultant-form', [
            'NewConsultantForm[full_name]' => 'Dr. Jane Mwangi',
            'NewConsultantForm[email]' => 'jane.mwangi@example.com',
            'NewConsultantForm[phone]' => '0722000111',
            'NewConsultantForm[practice_name]' => 'Mwangi Family Clinic',
            'NewConsultantForm[password]' => 'Secret-pass-1',
        ]);

        $user = User::findOne(['email' => 'jane.mwangi@example.com']);
        $I->assertNotNull($user);
        $I->assertSame('consultant', $user->role);
        $I->assertTrue($user->validatePassword('Secret-pass-1'));
        $consultant = Consultant::findOne(['user_id' => $user->id]);
        $I->assertSame('Dr. Jane Mwangi', $consultant->names);
        $I->assertSame(ConsultantWorkingHours::DEFAULT_WEEK, $consultant->getWeek());
        $I->seeInCurrentUrl('consultant/update');
    }

    public function adminManagesAnotherConsultantsAvailability(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('availability/index', ['cid' => $this->consultant->id]);
        $I->see('Availability: Dr. Test');

        $I->submitForm('#time-off-form', [
            'TimeOffForm[start_date]' => $this->date,
            'TimeOffForm[end_date]' => $this->date,
            'TimeOffForm[reason]' => 'Conference',
        ]);

        $I->assertTrue(ConsultantTimeOff::find()->where(['consultant_id' => $this->consultant->id, 'reason' => 'Conference'])->exists());
        $I->seeInCurrentUrl('cid=' . $this->consultant->id);
        $I->see('Availability: Dr. Test');
    }

    public function consultantsCannotManageSomeoneElsesAvailability(FunctionalTester $I)
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $other = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']);
        $other->save(false);

        // ?cid= is for admins only: a consultant always gets their own page
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index', ['cid' => $other->id]);
        $I->dontSee('Dr. Other');
        $I->see('Availability');
    }

    public function adminDeactivatesAConsultant(FunctionalTester $I)
    {
        $booked = $this->book('10:00');
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('manage-consultants/index');
        $I->postAsLink(['/site/deactivate-user', 'id' => $this->consultantUser->id]);

        $I->see('is deactivated and can no longer sign in.');
        $I->see('1 upcoming appointment(s) are kept');
        $I->assertSame(User::STATUS_DELETED, (int) User::findOne($this->consultantUser->id)->status);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($booked->id)->status, 'history is kept');

        // Patients can no longer book them or find them
        $refused = $this->newBooking('11:00', $this->otherClient);
        $I->assertFalse($refused->saveBooking());
        $I->assertStringContainsString('no longer taking appointments', $refused->getFirstError('time'));
        $found = array_map(function ($c) {
            return (int) $c->id;
        }, (new SearchForm())->findConsultants([], 'SearchForm'));
        $I->assertNotContains($this->consultant->id, $found);
    }

    public function aDeactivatedUserCannotSignIn(FunctionalTester $I)
    {
        User::updateAll(['status' => User::STATUS_DELETED], ['id' => $this->client->id]);

        // Sessions are restored through findIdentity() on every real request, so this also signs them out.
        // (The test client keeps the identity in memory between requests, so it is checked directly.)
        $I->assertNull(User::findIdentity($this->client->id));

        $I->amOnRoute('site/login');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'client', 'LoginForm[password]' => 'password_0']);
        $I->see('Incorrect username or password.');
    }

    public function adminReactivatesAnAccount(FunctionalTester $I)
    {
        User::updateAll(['status' => User::STATUS_DELETED], ['id' => $this->consultantUser->id]);
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/users');
        $I->postAsLink(['/site/reactivate-user', 'id' => $this->consultantUser->id]);

        $I->see('is active again.');
        $I->assertSame(User::STATUS_ACTIVE, (int) User::findOne($this->consultantUser->id)->status);
        $I->assertTrue($this->newBooking('11:00')->saveBooking());
    }

    public function adminsCannotDeactivateThemselves(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/users');
        $I->postAsLink(['/site/deactivate-user', 'id' => $this->admin->id]);

        $I->see('You cannot deactivate your own account.');
        $I->assertSame(User::STATUS_ACTIVE, (int) User::findOne($this->admin->id)->status);
    }

    public function adminEditsAnotherConsultantsProfile(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('consultant/update', ['id' => $this->consultant->id]);
        $I->seeResponseCodeIs(200);
        $I->seeInField('Consultant[names]', 'Dr. Test');

        $I->amLoggedInAs($this->otherClient);
        $I->amOnRoute('consultant/update', ['id' => $this->consultant->id]);
        $I->seeResponseCodeIs(403);
    }

    public function consultantsCannotEditAnotherConsultantsProfile(FunctionalTester $I)
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $I->amLoggedInAs($otherDoctor);
        $I->amOnRoute('consultant/update', ['id' => $this->consultant->id]);
        $I->seeResponseCodeIs(404);
    }
}
