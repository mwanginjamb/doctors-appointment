<?php

namespace frontend\tests\functional;

use common\components\RbacHelper;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityAdminClaim;
use frontend\models\FacilityRequest;
use frontend\services\FacilityAdmins;
use frontend\services\StandardTerms;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use Yii;

/**
 * Consultants who run a facility become its facility admin as well, and switch between their own
 * practice and managing it. Dr. Test works at Main clinic (08:00–13:00) and Avenue Clinic
 * (13:30–17:00) on Mondays, with a booking at each.
 */
class ConsultantFacilityAdminCest
{
    use BookingDataTrait;

    /** @var int */
    private $mainId;
    /** @var Facility */
    private $avenue;
    /** @var Appointments */
    private $atMain;
    /** @var Appointments */
    private $atAvenue;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        Yii::$app->session->remove(FacilityAdmins::SESSION_KEY);
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
        $this->atMain = $this->book('09:00');
        $this->atAvenue = $this->book('14:00', $this->otherClient);
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
    }

    private function claimMain(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->see('I run this facility', '.engagement-row[data-facility="' . $this->mainId . '"]');
        $I->submitForm('.engagement-row[data-facility="' . $this->mainId . '"] .claim-facility-form', []);

        return FacilityAdmins::pendingClaim($this->consultantUser->id);
    }

    private function approve(FacilityAdminClaim $claim)
    {
        Yii::$app->user->login($this->admin);
        $result = FacilityAdmins::approve($claim);
        Yii::$app->user->logout();
        FrontDesk::reset();

        return $result;
    }

    public function aConsultantClaimsAFacilityAndAnAdminApproves(FunctionalTester $I)
    {
        $claim = $this->claimMain($I);
        $I->see('Sent. The administrator checks with');
        $I->assertNotNull($claim);
        $I->assertSame($this->mainId, (int) $claim->facility_id);
        $I->see('Waiting: you run this facility');
        $I->dontSee('I run this facility', '.engagement-row[data-facility="' . $this->avenue->id . '"]');
        $I->assertNull(FacilityAdmins::runsFacilityId(User::findOne($this->consultantUser->id)), 'nothing granted yet');

        $I->assertTrue($this->approve($claim));
        $user = User::findOne($this->consultantUser->id);
        $I->assertSame($this->mainId, FacilityAdmins::runsFacilityId($user));
        $I->assertNotNull(Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $user->id));
        $I->assertNotNull(Yii::$app->authManager->getAssignment(RbacHelper::ROLE_CONSULTANT, $user->id), 'still a consultant');
        $I->assertSame(User::ACCOUNT_CONSULTANT, $user->role);

        $I->amLoggedInAs($user);
        $I->amOnRoute('availability/index');
        $I->see('You run this facility', '.engagement-row[data-facility="' . $this->mainId . '"]');
    }

    public function theyManageTheFacilityAndGoBackToTheirPractice(FunctionalTester $I)
    {
        $this->approve($this->claimMain($I));
        $user = User::findOne($this->consultantUser->id);

        // Their practice: their own calendar, no facility board in the menu
        $I->amLoggedInAs($user);
        $I->amOnRoute('site/index');
        $I->see('Manage Main', '#menu-facility-mode');
        $I->dontSeeElement('#facility-mode-bar');

        // Managing: the facility's board, every consultant's bookings there only
        $I->submitForm('#menu-facility-mode', []);
        $I->seeInCurrentUrl('board');
        $I->seeElement('#facility-mode-bar');
        $I->amOnRoute('board/index', ['from' => $this->date]);
        $I->see('Every consultant\'s appointments at Main clinic');
        $I->see('Client', 'table');
        $I->dontSee('Other Client', 'table');

        // Back to their practice
        $I->submitForm('#facility-mode-bar form', []);
        $I->amOnRoute('site/index');
        $I->dontSeeElement('#facility-mode-bar');
        $I->see('Manage Main', '#menu-facility-mode');
    }

    public function facilityNoticesReachThem(FunctionalTester $I)
    {
        $this->approve($this->claimMain($I));
        $ids = array_map('intval', FacilityAdmins::query($this->mainId)->select('id')->column());
        $I->assertContains((int) $this->consultantUser->id, $ids);
        $I->assertNotContains((int) $this->consultantUser->id, array_map('intval', FacilityAdmins::query($this->avenue->id)->select('id')->column()));
        $recipients = array_map(function ($u) { return (int) $u->id; }, StandardTerms::recipients($this->mainId));
        $I->assertContains((int) $this->consultantUser->id, $recipients);
    }

    public function oneFacilityAtATimeAndOnlyTheConsultantClaims(FunctionalTester $I)
    {
        $this->claimMain($I);
        // A second claim while one waits
        $I->dontSee('I run this facility', '.engagement-row[data-facility="' . $this->avenue->id . '"]');
        $I->assertIsString(FacilityAdmins::claim(User::findOne($this->consultantUser->id), $this->avenue));

        // An admin managing the consultant's availability cannot claim for them
        $engagement = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id]);
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('availability/index', ['cid' => $this->consultant->id]);
        $I->dontSee('I run this facility');

        // A facility they do not work at
        $elsewhere = new Facility(['name' => 'Elsewhere', 'active' => true]);
        $elsewhere->save(false);
        $doctor = $this->createUser('second.doctor', 'consultant');
        (new \frontend\models\Consultant(['user_id' => $doctor->id, 'names' => 'Dr. Second']))->save(false);
        $I->assertSame('Add the facility to your facilities first.', FacilityAdmins::claim($doctor, $elsewhere));
        $I->assertNotNull($engagement);
    }

    public function aDeclinedClaimGrantsNothing(FunctionalTester $I)
    {
        $claim = $this->claimMain($I);
        Yii::$app->user->login($this->admin);
        $I->assertTrue(FacilityAdmins::decline($claim, 'The facility says someone else runs it.'));
        Yii::$app->user->logout();
        FrontDesk::reset();

        $I->assertSame(FacilityAdminClaim::STATUS_DECLINED, FacilityAdminClaim::findOne($claim->id)->status);
        $I->assertNull(FacilityAdmins::runsFacilityId(User::findOne($this->consultantUser->id)));
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->see('I run this facility', '.engagement-row[data-facility="' . $this->mainId . '"]');
    }

    public function revokingEndsManagingAndKeepsTheConsultant(FunctionalTester $I)
    {
        $this->approve($this->claimMain($I));
        $user = User::findOne($this->consultantUser->id);
        $I->amLoggedInAs($user);
        $I->amOnRoute('site/index');
        $I->submitForm('#menu-facility-mode', []);
        $I->seeElement('#facility-mode-bar');

        Yii::$app->user->login($this->admin);
        $I->assertTrue(FacilityAdmins::revoke(User::findOne($user->id), 'Left the clinic'));
        FrontDesk::reset();
        $user = User::findOne($user->id);
        $I->assertNull($user->facility_id);
        $I->assertNull(Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $user->id));
        $I->assertNotNull(Yii::$app->authManager->getAssignment(RbacHelper::ROLE_CONSULTANT, $user->id));

        $I->amLoggedInAs($user);
        $I->amOnRoute('site/index');
        $I->dontSeeElement('#facility-mode-bar');
        $I->dontSeeElement('#menu-facility-mode');
        $I->amOnRoute('board/index');
        $I->seeResponseCodeIs(403);
    }

    public function aNewFacilityTheyRunMakesThemItsAdminWhenListed(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#facility-request-form', [
            'FacilityRequest[name]' => 'Njambi Dermatology Clinic',
            'FacilityRequest[address]' => 'Serene Plaza, Thika',
            'FacilityRequest[runs_facility]' => '1',
        ]);
        $request = FacilityRequest::findOne(['name' => 'Njambi Dermatology Clinic']);
        $I->assertSame(1, (int) $request->runs_facility);
        $claim = FacilityAdminClaim::findOne(['facility_request_id' => $request->id]);
        $I->assertSame(FacilityAdminClaim::STATUS_PENDING, $claim->status);
        $I->assertNull($claim->facility_id);

        // The admin lists it: the consultant runs it, with no second approval
        Yii::$app->user->login($this->admin);
        $facility = new Facility(['name' => 'Njambi Dermatology Clinic', 'address' => 'Serene Plaza, Thika', 'active' => true]);
        $I->assertTrue($request->approve($facility));
        Yii::$app->user->logout();
        FrontDesk::reset();

        $I->assertSame(FacilityAdminClaim::STATUS_APPROVED, FacilityAdminClaim::findOne($claim->id)->status);
        $I->assertSame((int) $facility->id, FacilityAdmins::runsFacilityId(User::findOne($this->consultantUser->id)));
    }

    public function aDeclinedRequestCancelsItsClaim(FunctionalTester $I)
    {
        $request = new FacilityRequest(['name' => 'Nowhere Clinic', 'runs_facility' => 1]);
        $request->consultant_id = $this->consultant->id;
        $request->save(false);
        $request = FacilityRequest::findOne($request->id); // its status from the table's default
        $claim = FacilityAdmins::claimForRequest($request);
        $I->assertNotNull($claim);

        Yii::$app->user->login($this->admin);
        $I->assertTrue($request->decline('Not a real clinic'));
        Yii::$app->user->logout();
        $I->assertSame(FacilityAdminClaim::STATUS_CANCELLED, FacilityAdminClaim::findOne($claim->id)->status);
    }
}
