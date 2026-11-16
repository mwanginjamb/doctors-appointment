<?php

namespace frontend\tests\functional;

use common\models\AuditLog;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F21: patients' phone numbers and emails are partly hidden in lists and boards, and shown in
 * full only on an appointment the viewer may handle, where staff views are audited once a day.
 */
class MaskedContactsCest
{
    use BookingDataTrait;

    /** @var \frontend\models\Appointments */
    private $appointment;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->appointment = $this->book('10:00');
    }

    private function contactViews()
    {
        return (int) AuditLog::find()->where([
            'action' => AuditLog::ACTION_CONTACT_VIEWED,
            'entity_type' => 'appointment',
            'entity_id' => (string) $this->appointment->id,
        ])->count();
    }

    public function theBoardHidesPatientsNumbers(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('board/index', ['from' => $this->date]);

        $I->see('0712***678', 'table');
        $I->dontSee('0712345678', 'table');
    }

    public function theUsersListHidesPatientsButNotStaff(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/users', ['q' => 'client']);

        $I->see('c***@example.com', 'table');
        $I->dontSee('client@example.com', 'table');

        $I->amOnRoute('site/users', ['q' => 'doctor']);
        $I->see('doctor@example.com', 'table');
    }

    public function staffSeeTheContactInFullOnTheAppointmentAndItIsAuditedOnceADay(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->seeInSource('client@example.com');
        $I->seeInSource('0712345678');
        $I->assertSame(1, $this->contactViews());

        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->assertSame(1, $this->contactViews(), 'a refresh is not logged again');

        // Another member of staff is logged separately
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->assertSame(2, $this->contactViews());
    }

    public function thePatientSeesTheirOwnWithoutAnAuditEntry(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);

        $I->seeInSource('client@example.com');
        $I->assertSame(0, $this->contactViews());
    }
}
