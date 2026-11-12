<?php

namespace frontend\tests\functional;

use common\components\RbacHelper;
use common\models\AuditLog;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityClosure;
use frontend\models\FacilityInvitation;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * Facility admins. Dr. Test works at Main clinic on Mondays 08:00–13:00 and at Avenue Clinic
 * 13:30–17:00. The facility admin runs Main clinic; Dr. Other works nowhere near it (only at
 * Westlands Clinic).
 */
class FacilityAdminCest
{
    use BookingDataTrait;

    /** @var User */
    private $facilityAdmin;
    /** @var int */
    private $mainId;
    /** @var Facility */
    private $avenue;
    /** @var Facility */
    private $westlands;
    /** @var Consultant */
    private $other;
    /** @var Appointments */
    private $atMain;
    /** @var Appointments */
    private $atAvenue;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        FacilityClosure::reset();
        $this->createParticipants();

        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->avenue = new Facility(['name' => 'Avenue Clinic', 'active' => true]);
        $this->avenue->save(false);
        (new ConsultantFacility([
            'consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id,
            'session_minutes' => 30, 'booking_open' => true,
        ]))->save(false);
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [
            1 => [['08:00:00', '13:00:00', $this->mainId], ['13:30:00', '17:00:00', $this->avenue->id]],
        ]);
        $this->consultant = Consultant::findOne($this->consultant->id);

        $this->westlands = new Facility(['name' => 'Westlands Clinic', 'active' => true]);
        $this->westlands->save(false);
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $this->other = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other', 'facility' => 'Westlands Clinic',
            'consultant_email' => 'other@example.com', 'consultant_phone_number' => '0712000001']);
        $this->other->save(false); // engaged at Westlands Clinic, from their profile

        $this->facilityAdmin = $this->createUser('main.admin', User::ACCOUNT_FACILITY_ADMIN);
        $this->facilityAdmin->updateAttributes(['facility_id' => $this->mainId]);

        $this->atMain = $this->book('09:00');
        $this->atAvenue = $this->book('14:00', $this->otherClient);
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
    }

    // ---- Accounts ------------------------------------------------------------------------------

    public function consultancyAdminCreatesAFacilityAdmin(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility-admin/create');
        $I->submitForm('#facility-admin-form', [
            'FacilityAdminForm[full_name]' => 'Avenue Manager',
            'FacilityAdminForm[email]' => 'avenue.manager@example.com',
            'FacilityAdminForm[password]' => 'Secret-pass-1',
            'FacilityAdminForm[facility_id]' => $this->avenue->id,
        ]);

        $I->see('Facility admin created.');
        $user = User::findOne(['email' => 'avenue.manager@example.com']);
        $I->assertSame(User::ACCOUNT_FACILITY_ADMIN, $user->role);
        $I->assertSame($this->avenue->id, (int) $user->facility_id);
        $I->assertNotNull(\Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $user->id));

        $I->amOnRoute('facility-admin/index');
        $I->see('Avenue Clinic', 'table');
    }

    public function onlyConsultancyAdminsManageFacilityAdmins(FunctionalTester $I)
    {
        foreach ([$this->facilityAdmin, $this->consultantUser] as $user) {
            $I->amLoggedInAs($user);
            $I->amOnRoute('facility-admin/index');
            $I->seeResponseCodeIs(403);
        }
    }

    // ---- Their facility only -------------------------------------------------------------------

    public function seesEveryAppointmentAtTheirFacilityOnly(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('board/index', ['from' => $this->date]);

        $I->see('Every consultant\'s appointments at Main clinic');
        $I->see('Client', 'table');
        $I->dontSee('Other Client', 'table');
        $I->see('Numbers for Main clinic');
        $I->seeElement('.facility-report');

        $I->amOnRoute('board/index', ['from' => $this->date, 'facility' => $this->avenue->id]);
        $I->dontSee('Other Client', 'table');
    }

    public function handlesAppointmentsThereButDoesNotBook(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('appointments/view', ['id' => $this->atAvenue->id]);
        $I->seeResponseCodeIs(404);

        $I->amOnRoute('appointments/view', ['id' => $this->atMain->id]);
        $I->seeResponseCodeIs(200);
        $I->submitForm('#cancel-appointment-form', ['note' => 'Clinic closed early']);
        $I->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($this->atMain->id)->status);

        $refused = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '10:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $this->otherClient->id,
        ]);
        $I->assertSame('error', $refused['status']);
    }

    // ---- Front desk ----------------------------------------------------------------------------

    public function managesOnlyTheirFacilitysFrontDesk(FunctionalTester $I)
    {
        $elsewhere = $this->createUser('avenue.desk', FrontDesk::ROLE);
        $elsewhere->updateAttributes(['facility_id' => $this->avenue->id]);

        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('front-desk/index');
        $I->see('Front Desk Staff: Main clinic');
        $I->dontSee('Avenue Desk', 'table');
        $I->amOnRoute('front-desk/update', ['id' => $elsewhere->id]);
        $I->seeResponseCodeIs(404);

        // Every consultant is listed, and a posted facility is ignored: front desk works here
        $I->amOnRoute('front-desk/create');
        $I->see('Dr. Other', '#front-desk-consultants');
        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[full_name]' => 'Main Reception',
            'FrontDeskStaffForm[email]' => 'main.reception@example.com',
            'FrontDeskStaffForm[password]' => 'Secret-pass-1',
            'FrontDeskStaffForm[facility_id]' => $this->avenue->id,
            'FrontDeskStaffForm[consultant_ids]' => [$this->consultant->id],
        ]);
        $I->see('Front-desk account created.');
        $created = User::findOne(['email' => 'main.reception@example.com']);
        $I->assertSame($this->mainId, (int) $created->facility_id);
        $I->assertSame([$this->consultant->id], FrontDeskAssignment::consultantIdsFor($created->id));
    }

    public function tickingAConsultantFromElsewhereInvitesThem(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('front-desk/create');
        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[full_name]' => 'Main Reception',
            'FrontDeskStaffForm[email]' => 'main.reception@example.com',
            'FrontDeskStaffForm[password]' => 'Secret-pass-1',
            'FrontDeskStaffForm[consultant_ids]' => [$this->consultant->id, $this->other->id],
        ]);
        $I->see('Dr. Other has been invited to work at Main clinic');
        $invitation = FacilityInvitation::findOne(['facility_id' => $this->mainId, 'consultant_id' => $this->other->id]);
        $I->assertNotNull($invitation);
        $I->assertSame(FacilityInvitation::STATUS_PENDING, $invitation->status);
        $I->assertNull(FacilityInvitation::findOne(['consultant_id' => $this->consultant->id]), 'Dr. Test already works there');

        // The front desk sees them as invited, not bookable
        $desk = User::findOne(['email' => 'main.reception@example.com']);
        FrontDesk::reset();
        $I->amLoggedInAs($desk);
        $I->amOnRoute('board/index');
        $I->seeLink('Book with Dr. Test');
        $I->dontSeeLink('Book with Dr. Other');
        $I->see('Dr. Other', '#waiting-consultants');

        // Dr. Other accepts by adding Main clinic on their Availability page
        $I->amLoggedInAs(User::findOne($this->other->user_id));
        $I->amOnRoute('availability/index');
        $I->see('Main clinic invites you to see patients there', '.facility-invitation');
        $I->amOnRoute('availability/index', ['invite' => $this->mainId]);
        $I->submitForm('#add-facility-form', [
            'ConsultantFacility[facility_id]' => $this->mainId,
            'ConsultantFacility[session_minutes]' => 30,
        ]);
        $I->assertSame(FacilityInvitation::STATUS_ACCEPTED, FacilityInvitation::findOne($invitation->id)->status);
    }

    public function changesAndClearsTheirFrontDesksConsultants(FunctionalTester $I)
    {
        $desk = $this->createUser('main.desk', FrontDesk::ROLE);
        $desk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($desk->id, [$this->consultant->id]);

        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('front-desk/update', ['id' => $desk->id]);
        $I->uncheckOption('#front-desk-consultants input[value="' . $this->consultant->id . '"]');
        $I->checkOption('#front-desk-consultants input[value="' . $this->other->id . '"]');
        $I->click('Save');
        $I->assertSame([$this->other->id], FrontDeskAssignment::consultantIdsFor($desk->id));

        $I->amOnRoute('front-desk/update', ['id' => $desk->id]);
        $I->uncheckOption('#front-desk-consultants input[value="' . $this->other->id . '"]');
        $I->click('Save');
        $I->see('saved.');
        $I->assertSame([], FrontDeskAssignment::consultantIdsFor($desk->id));
    }

    public function decliningAnInvitationRemovesThemFromThatFrontDesk(FunctionalTester $I)
    {
        $desk = $this->createUser('main.desk', FrontDesk::ROLE);
        $desk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($desk->id, [$this->consultant->id, $this->other->id]);
        $invitation = FacilityInvitation::invite($this->mainId, $this->other->id);

        $I->amLoggedInAs(User::findOne($this->other->user_id));
        $I->amOnRoute('availability/index');
        $I->submitForm('.facility-invitation form', []);

        $I->see('Invitation from Main clinic declined.');
        $I->assertSame(FacilityInvitation::STATUS_DECLINED, FacilityInvitation::findOne($invitation->id)->status);
        $I->assertSame([$this->consultant->id], FrontDeskAssignment::consultantIdsFor($desk->id));
    }

    // ---- Their facility's details, closed days and audit log -----------------------------------

    public function editsOnlyTheContactDetailsOfTheirFacility(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('facility/update', ['id' => $this->mainId]);
        $I->see('My Facility: Main clinic');
        $I->dontSeeElement('input[name="Facility[name]"]');
        $I->submitForm('#facility-form', [
            'Facility[name]' => 'Renamed',
            'Facility[phone]' => '0720 111 222',
            'Facility[active]' => 0,
        ]);
        $facility = Facility::findOne($this->mainId);
        $I->assertSame('Main clinic', $facility->name);
        $I->assertSame('0720 111 222', $facility->phone);
        $I->assertTrue($facility->isActive());

        foreach (['facility/update', 'facility/closures'] as $route) {
            $I->amOnRoute($route, ['id' => $this->avenue->id]);
            $I->seeResponseCodeIs(404);
        }
        $I->amOnRoute('facility/index');
        $I->seeResponseCodeIs(403);
    }

    public function closesTheirFacilityForADay(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('facility/closures', ['id' => $this->mainId]);
        $I->submitForm('#facility-closure-form', [
            'FacilityClosure[date]' => $this->date,
            'FacilityClosure[reason]' => 'Staff training',
        ]);
        $I->see('Main clinic is closed on');
        $I->assertNotNull(FacilityClosure::findOne(['facility_id' => $this->mainId, 'date' => $this->date]));

        $other = new FacilityClosure(['facility_id' => $this->avenue->id, 'date' => $this->date, 'reason' => 'Painting']);
        $other->save(false);
        $I->sendCsrfPost(['/facility/delete-closure', 'id' => $other->id]);
        $I->seeResponseCodeIs(404);
        $I->assertNotNull(FacilityClosure::findOne($other->id));
    }

    public function readsOnlyTheirFacilitysAuditLog(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('audit/index', ['facility' => $this->avenue->id]);
        $I->seeResponseCodeIs(200);

        // Both appointments were logged when booked, each with its facility
        $I->assertTrue(AuditLog::find()->where(['facility_id' => $this->avenue->id, 'entity_id' => (string) $this->atAvenue->id])->exists());
        $I->seeLink('Appointment #' . $this->atMain->id);
        $I->dontSeeLink('Appointment #' . $this->atAvenue->id);
        $I->dontSee('Avenue Clinic', 'table');
    }
}
