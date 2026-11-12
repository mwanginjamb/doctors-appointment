<?php

namespace frontend\tests\functional;

use common\components\RbacHelper;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class FrontDeskCest
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;
    /** @var User */
    private $otherDoctor;
    /** @var Consultant */
    private $otherConsultant;
    /** @var Appointments with Dr. Test, whom the front desk works for */
    private $assignedAppointment;
    /** @var Appointments with Dr. Other, whom they don't */
    private $otherAppointment;
    /** @var int the facility both doctors work at (their default one) */
    private $facilityId;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();

        $this->otherDoctor = $this->createUser('other.doctor', 'consultant');
        $this->otherConsultant = new Consultant(['user_id' => $this->otherDoctor->id, 'names' => 'Dr. Other']);
        $this->otherConsultant->save(false);

        $this->facilityId = (int) \frontend\models\ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $this->frontDesk->updateAttributes(['facility_id' => $this->facilityId]);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);

        $this->assignedAppointment = $this->book('10:00');
        $this->otherAppointment = $this->newBooking('11:00', $this->otherClient);
        $this->otherAppointment->consultant_id = $this->otherDoctor->id;
        $this->otherAppointment->saveBooking();
    }

    public function adminCreatesFrontDeskStaffWithConsultants(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('front-desk/create');
        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[full_name]' => 'Wanjiru Reception',
            'FrontDeskStaffForm[email]' => 'reception@example.com',
            'FrontDeskStaffForm[phone]' => '0711000000',
            'FrontDeskStaffForm[password]' => 'Secret-pass-1',
            'FrontDeskStaffForm[facility_id]' => $this->facilityId,
            'FrontDeskStaffForm[consultant_ids]' => [$this->consultant->id, $this->otherConsultant->id],
        ]);

        $I->see('Front-desk account created.');
        $user = User::findOne(['email' => 'reception@example.com']);
        $I->assertNotNull($user);
        $I->assertSame(FrontDesk::ROLE, $user->role);
        $I->assertSame('reception@example.com', $user->username);
        $I->assertSame($this->facilityId, (int) $user->facility_id);
        $I->assertTrue($user->validatePassword('Secret-pass-1'));
        $I->assertNotNull(\Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FRONT_DESK, $user->id));
        $ids = FrontDeskAssignment::consultantIdsFor($user->id);
        sort($ids);
        $I->assertSame([$this->consultant->id, $this->otherConsultant->id], $ids);
    }

    public function adminChangesAndClearsAssignments(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('front-desk/update', ['id' => $this->frontDesk->id]);
        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[consultant_ids]' => [$this->otherConsultant->id],
        ]);
        $I->assertSame([$this->otherConsultant->id], FrontDeskAssignment::consultantIdsFor($this->frontDesk->id));

        $I->amOnRoute('front-desk/update', ['id' => $this->frontDesk->id]);
        $I->submitForm('#front-desk-staff-form', ['FrontDeskStaffForm[consultant_ids]' => '']);
        $I->assertSame([], FrontDeskAssignment::consultantIdsFor($this->frontDesk->id));
    }

    public function onlyAdminsManageFrontDeskStaff(FunctionalTester $I)
    {
        foreach ([$this->frontDesk, $this->consultantUser] as $user) {
            $I->amLoggedInAs($user);
            $I->amOnRoute('front-desk/index');
            $I->seeResponseCodeIs(403);
        }
    }

    public function boardShowsOnlyTheAssignedConsultants(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('board/index', ['from' => $this->date]);

        $I->seeResponseCodeIs(200);
        $I->see('Dr. Test', 'table');
        $I->dontSee('Dr. Other', 'table');
        $I->dontSee('Dr. Other', '#board-consultant');
        $I->seeLink('Book with Dr. Test');

        // Asking for another consultant's appointments still shows none of them
        $I->amOnRoute('board/index', ['from' => $this->date, 'consultant' => $this->otherDoctor->id]);
        $I->dontSee('Dr. Other', 'table');
    }

    public function frontDeskWithoutConsultantsSeesNothing(FunctionalTester $I)
    {
        FrontDeskAssignment::replaceFor($this->frontDesk->id, []);
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('board/index', ['from' => $this->date]);

        $I->see('No consultants are assigned to you yet.');
        $I->dontSee('Dr. Test', 'table');
    }

    public function booksOnlyForAssignedConsultants(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);

        $booked = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '14:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $this->otherClient->id,
        ]);
        $I->assertSame('success', $booked['status'], json_encode($booked));
        $appointment = Appointments::find()->where(['date' => $this->date, 'time' => '14:00:00'])->one();
        $I->assertSame($this->otherClient->id, (int) $appointment->patient_id);
        $I->assertSame($this->frontDesk->id, (int) $appointment->created_by);

        $refused = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '15:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->otherDoctor->id, 'patient' => $this->otherClient->id,
        ]);
        $I->assertSame('error', $refused['status']);
        $I->assertFalse(Appointments::find()->where(['date' => $this->date, 'time' => '15:00:00'])->exists());
    }

    public function movesAndCancelsOnlyAssignedConsultantsAppointments(FunctionalTester $I)
    {
        $toMove = $this->book('12:00', $this->otherClient);
        $I->amLoggedInAs($this->frontDesk);

        // Pages first: in tests the app is reused, and the JSON API calls below switch its response format
        $I->amOnRoute('appointments/view', ['id' => $this->assignedAppointment->id]);
        $I->submitForm('#cancel-appointment-form', ['note' => 'Patient phoned to cancel']);
        $I->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($this->assignedAppointment->id)->status);

        $I->amOnRoute('appointments/view', ['id' => $this->otherAppointment->id]);
        $I->seeResponseCodeIs(404);

        $moved = $I->sendJsonPost(['/api/update-visit'], ['id' => $toMove->id, 'date' => $this->date, 'time' => '15:00:00']);
        $I->assertSame('success', $moved['status'], json_encode($moved));
        $notMoved = $I->sendJsonPost(['/api/update-visit'], ['id' => $this->otherAppointment->id, 'date' => $this->date, 'time' => '15:00:00']);
        $I->assertSame('error', $notMoved['status']);
    }

    public function opensOnlyAssignedCalendarsAndCanBookThere(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);

        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->seeResponseCodeIs(200);
        $I->seeInSource('"readOnly":false');
        $I->seeInSource('"noticeMinutes":0');
        $I->seeElement('#patient-lookup');
        $I->seeElement('#walk-in-form');

        $I->amOnRoute('appointments/calendar', ['cid' => $this->otherConsultant->id]);
        $I->seeResponseCodeIs(403);
    }

    public function registersAWalkInWithoutEmailAndBooksThem(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);

        $registered = $I->sendJsonPost(['/api/register-patient'], ['consent' => true, 'reminders' => true, 'full_name' => 'Achieng Walk-in', 'phone' => '+254 733 111 222']);
        $I->assertSame('success', $registered['status'], json_encode($registered));

        $patient = User::findOne($registered['patient']['id']);
        $I->assertSame('client', $patient->role);
        $I->assertNull($patient->email);
        $I->assertSame('+254733111222', $patient->phone_number);
        $I->assertSame(User::STATUS_ACTIVE, (int) $patient->status);

        $booked = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '14:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $patient->id,
        ]);
        $I->assertSame('success', $booked['status'], json_encode($booked));
    }

    public function aPhoneAlreadyInUseNeedsConfirmation(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);

        // The fixture patients all use 0712345678
        $first = $I->sendJsonPost(['/api/register-patient'], ['consent' => true, 'reminders' => true, 'full_name' => 'Family Member', 'phone' => '0712 345 678']);
        $I->assertSame('exists', $first['status']);
        $I->assertCount(2, $first['patients']);
        $I->assertFalse(User::find()->where(['full_name' => 'Family Member'])->exists());

        $confirmed = $I->sendJsonPost(['/api/register-patient'], ['consent' => true, 'reminders' => true, 'full_name' => 'Family Member', 'phone' => '0712 345 678', 'confirm_new' => true]);
        $I->assertSame('success', $confirmed['status'], json_encode($confirmed));
    }

    public function aWalkInsEmailMustNotBelongToAnotherAccount(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);

        $response = $I->sendJsonPost(['/api/register-patient'], ['consent' => true, 'reminders' => true, 'full_name' => 'Someone', 'phone' => '0799000111', 'email' => 'client@example.com']);
        $I->assertSame('error', $response['status']);
        $I->assertStringContainsString('already uses this email', $response['message']);
    }

    public function patientsCannotRegisterPatientsButConsultantsCan(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/api/register-patient'], json_encode(['full_name' => 'X Y', 'phone' => '0799000222']));
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs($this->consultantUser);
        $response = $I->sendJsonPost(['/api/register-patient'], ['consent' => true, 'reminders' => true, 'full_name' => 'Walk In', 'phone' => '0799000333']);
        $I->assertSame('success', $response['status'], json_encode($response));
    }
}
