<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\helpers\Json;

class AppointmentsBoardCest
{
    use BookingDataTrait;

    /** @var Appointments */
    private $withDrTest;
    /** @var Appointments */
    private $withDrOther;
    /** @var Consultant */
    private $otherConsultant;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->withDrTest = $this->book('10:00');

        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $this->otherConsultant = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']);
        $this->otherConsultant->save(false);
        $this->withDrOther = $this->newBooking('11:00', $this->otherClient);
        $this->withDrOther->consultant_id = $otherDoctor->id;
        $this->withDrOther->saveBooking();

        // Fixtures are saved with nobody logged in; record who booked each one
        Appointments::updateAll(['created_by' => $this->client->id], ['id' => $this->withDrTest->id]);
        Appointments::updateAll(['created_by' => $otherDoctor->id], ['id' => $this->withDrOther->id]);
    }

    public function onlyAdminsSeeTheBoard(FunctionalTester $I)
    {
        foreach ([$this->client, $this->consultantUser] as $user) {
            $I->amLoggedInAs($user);
            $I->amOnRoute('board/index');
            $I->seeResponseCodeIs(403);
        }
    }

    public function adminSeesEveryConsultantsAppointmentsForTheDay(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('board/index', ['from' => $this->date]);

        $I->seeResponseCodeIs(200);
        $I->see('Dr. Test', 'table');
        $I->see('Dr. Other', 'table');
        $I->see('Client', 'table');
        $I->see('Other Client', 'table');
        $I->see('2 scheduled');
        $I->see('Patient online', 'table');
        $I->see('Consultant', 'table tbody');
    }

    public function todayIsTheDefault(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('board/index');

        $I->see(date('l j M Y'));
        $I->dontSee('Dr. Other', 'table');
    }

    public function filtersByConsultantStatusAndPatient(FunctionalTester $I)
    {
        $this->withDrOther->cancel('Doctor unwell');
        $I->amLoggedInAs($this->admin);

        $I->amOnRoute('board/index', ['from' => $this->date, 'consultant' => $this->consultantUser->id]);
        $I->see('Dr. Test', 'table');
        $I->dontSee('Dr. Other', 'table');

        $I->amOnRoute('board/index', ['from' => $this->date, 'status' => Appointments::STATUS_CANCELLED]);
        $I->see('Cancelled: Doctor unwell', 'table');
        $I->dontSee('Dr. Test', 'table');

        $I->amOnRoute('board/index', ['from' => $this->date, 'status' => 'active']);
        $I->see('Dr. Test', 'table');
        $I->dontSee('Dr. Other', 'table');

        $I->amOnRoute('board/index', ['from' => $this->date, 'q' => 'Other Client']);
        $I->see('Dr. Other', 'table');
        $I->dontSee('Dr. Test', 'table');
    }

    public function adminOpensAndCancelsAnyAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('appointments/view', ['id' => $this->withDrTest->id]);
        $I->seeResponseCodeIs(200);

        $I->submitForm('#cancel-appointment-form', ['note' => 'Clinic closed for maintenance']);

        $cancelled = Appointments::findOne($this->withDrTest->id);
        $I->assertSame(Appointments::STATUS_CANCELLED, $cancelled->status);
        $I->assertSame('Clinic closed for maintenance', $cancelled->cancellation_note);
        $I->assertSame($this->admin->id, (int) $cancelled->updated_by);
    }

    public function adminCannotEditOrConfirmOthersAppointments(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('appointments/update', ['id' => $this->withDrTest->id]);
        $I->seeResponseCodeIs(404);

        $I->sendCsrfPost(['/appointments/confirm', 'id' => $this->withDrTest->id]);
        $I->seeResponseCodeIs(404);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->withDrTest->id)->status);
    }

    public function adminSeesAConsultantsCalendarReadOnly(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->otherConsultant->id]);
        $I->seeResponseCodeIs(200);
        $I->see('Schedule for Dr. Other');
        $I->seeInSource('"readOnly":true');

        $I->amOnRoute('api/appointments');
        $events = Json::decode($I->grabPageSource());
        $I->assertSame([$this->withDrOther->id], array_column($events, 'id'));
        $I->assertStringContainsString('Other Client', $events[0]['title']);
    }
}
