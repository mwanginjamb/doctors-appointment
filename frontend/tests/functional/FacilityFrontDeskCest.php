<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityClosure;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\helpers\Json;

/**
 * F8 phase 2 pages. Dr. Test works at Main clinic on Mondays 08:00–13:00 and at Avenue Clinic
 * 13:30–17:00; the front desk works at Main clinic for Dr. Test.
 */
class FacilityFrontDeskCest
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;
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
        FacilityClosure::reset();
        $this->createParticipants();

        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->avenue = new Facility(['name' => 'Avenue Clinic', 'active' => true]);
        $this->avenue->save(false);
        (new ConsultantFacility([
            'consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id,
            'session_minutes' => 30, 'fee' => 3500, 'booking_open' => true,
        ]))->save(false);
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [
            1 => [['08:00:00', '13:00:00', $this->mainId], ['13:30:00', '17:00:00', $this->avenue->id]],
        ]);
        $this->consultant = Consultant::findOne($this->consultant->id);

        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $this->frontDesk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);

        $this->atMain = $this->book('09:00');
        $this->atAvenue = $this->book('14:00', $this->otherClient);
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
        FacilityClosure::reset();
    }

    public function frontDeskSeesOnlyTheirFacilityOnTheBoard(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('board/index', ['from' => $this->date]);

        $I->see('Appointments at Main clinic');
        $I->see('Client', 'table');
        $I->dontSee('Other Client', 'table');
        $I->dontSeeElement('#board-facility');

        // Asking for the other facility shows nothing of it
        $I->amOnRoute('board/index', ['from' => $this->date, 'facility' => $this->avenue->id]);
        $I->dontSee('Other Client', 'table');
    }

    public function frontDeskCannotOpenAppointmentsElsewhere(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('appointments/view', ['id' => $this->atMain->id]);
        $I->seeResponseCodeIs(200);
        $I->amOnRoute('appointments/view', ['id' => $this->atAvenue->id]);
        $I->seeResponseCodeIs(404);
    }

    public function theCalendarShowsOtherFacilitiesOnlyAsBusy(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->see('Front desk: Dr. Test at Main clinic');
        // Only the hours at Main clinic are offered to them
        $I->seeInSource('"startTime":"08:00","endTime":"13:00"');
        $I->dontSeeInSource('"startTime":"13:30","endTime":"17:00","facility"');

        $I->amOnRoute('api/appointments');
        $events = Json::decode($I->grabPageSource());
        $I->assertSame([$this->atMain->id], array_values(array_filter(array_column($events, 'id'))));
        $busy = array_values(array_filter($events, function ($e) {
            return !isset($e['id']);
        }));
        $I->assertCount(1, $busy);
        $I->assertSame('Booked', $busy[0]['title']);
        $I->assertStringNotContainsString('Other Client', json_encode($events));
    }

    public function frontDeskBooksOnlyAtTheirFacility(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $refused = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '15:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $this->otherClient->id,
        ]);
        $I->assertSame('error', $refused['status']);
        $I->assertStringContainsString('You book only into the hours at Main clinic', $refused['message']);

        $booked = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date, 'time' => '10:00:00', 'brief' => 'Walk-in',
            'consultant' => $this->consultantUser->id, 'patient' => $this->otherClient->id,
        ]);
        $I->assertSame('success', $booked['status'], json_encode($booked));

        $notMoved = $I->sendJsonPost(['/api/update-visit'], ['id' => $this->atAvenue->id, 'date' => $this->date, 'time' => '10:30:00']);
        $I->assertSame('error', $notMoved['status']);
    }

    public function adminChoosesAFacilityAndConsultantsWhoWorkThere(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('front-desk/update', ['id' => $this->frontDesk->id]);
        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[facility_id]' => '',
            'FrontDeskStaffForm[consultant_ids]' => [$this->consultant->id],
        ]);
        $I->see('Choose the facility they work at.');

        $elsewhere = new Facility(['name' => 'Westlands Clinic', 'active' => true]);
        $elsewhere->save(false);
        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[facility_id]' => $elsewhere->id,
            'FrontDeskStaffForm[consultant_ids]' => [$this->consultant->id],
        ]);
        // Not working there yet: they are invited, not refused
        $I->see('Dr. Test has been invited to work at Westlands Clinic');
        $I->amOnRoute('front-desk/update', ['id' => $this->frontDesk->id]);

        $I->submitForm('#front-desk-staff-form', [
            'FrontDeskStaffForm[facility_id]' => $this->avenue->id,
            'FrontDeskStaffForm[consultant_ids]' => [$this->consultant->id],
        ]);
        $I->see('saved.');
        $I->assertSame($this->avenue->id, (int) User::findOne($this->frontDesk->id)->facility_id);

        $I->amOnRoute('front-desk/index');
        $I->see('Avenue Clinic', 'table');
    }

    public function frontDeskWithNoFacilityIsAskedToGetOneSet(FunctionalTester $I)
    {
        $this->frontDesk->updateAttributes(['facility_id' => null]);
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('front-desk/index');
        $I->see('Not set: sees every facility');

        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('board/index', ['from' => $this->date]);
        $I->seeElement('#no-facility-warning');
        $I->see('Other Client', 'table');
    }

    public function adminClosesAFacilityForADay(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility/closures', ['id' => $this->avenue->id]);
        $I->submitForm('#facility-closure-form', [
            'FacilityClosure[date]' => $this->date,
            'FacilityClosure[reason]' => 'Staff training',
        ]);

        $I->see('Avenue Clinic is closed on');
        $I->see('1 appointment(s) are already booked there that day');
        $I->see('Staff training', 'table.closures');
        $I->see('1 to move', 'table.closures');
        $closure = FacilityClosure::findOne(['facility_id' => $this->avenue->id, 'date' => $this->date]);
        $I->assertNotNull($closure);

        // The booked appointment is kept
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->atAvenue->id)->status);

        $I->submitForm('table.closures form', []);
        $I->see('Avenue Clinic is open again');
        $I->assertNull(FacilityClosure::findOne($closure->id));
    }

    public function onlyAdminsCloseFacilities(FunctionalTester $I)
    {
        foreach ([$this->frontDesk, $this->consultantUser] as $user) {
            $I->amLoggedInAs($user);
            $I->amOnRoute('facility/closures', ['id' => $this->avenue->id]);
            $I->seeResponseCodeIs(403);
        }
    }

    public function consultantSetsTheirTravelTime(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->seeElement('#travel-buffer-form');
        $I->submitForm('#travel-buffer-form', ['travel_buffer_minutes' => '45']);

        $I->see('Travel time saved: 45 minutes');
        $I->assertSame(45, (int) Consultant::findOne($this->consultant->id)->travel_buffer_minutes);

        $I->sendCsrfPost(['/availability/travel-buffer'], '');
        $I->assertSame(45, (int) Consultant::findOne($this->consultant->id)->travel_buffer_minutes, 'a missing value saves nothing');
    }

    public function adminDashboardReportsPerFacility(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/settings', ['month' => substr($this->date, 0, 7)]);
        $I->seeElement('#dashboard-facility');
        $I->see('Per facility', '.facility-report');
        $I->see('Main clinic', '.facility-report');
        $I->see('Avenue Clinic', '.facility-report');

        $I->amOnRoute('site/settings', ['month' => substr($this->date, 0, 7), 'facility' => $this->avenue->id]);
        $I->see('Numbers for Avenue Clinic');
        $I->dontSee('Main clinic', '.facility-report');
    }
}
