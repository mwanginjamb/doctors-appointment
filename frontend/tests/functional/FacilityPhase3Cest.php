<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Consultant;
use frontend\models\ConsultantExternalBlock;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantSession;
use frontend\models\Facility;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F8 phase 3 pages: one-off sessions, rotations, busy elsewhere and the calendar feed. Dr. Test
 * works at Main clinic and Avenue Clinic; the front desk works at Main clinic for Dr. Test.
 */
class FacilityPhase3Cest
{
    use BookingDataTrait;

    /** @var int */
    private $mainId;
    /** @var Facility */
    private $avenue;
    /** @var User */
    private $frontDesk;
    /** @var string a Sunday in the booking window: the default week has no hours */
    private $sunday;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->avenue = new Facility(['name' => 'Avenue Clinic', 'active' => true]);
        $this->avenue->save(false);
        (new ConsultantFacility([
            'consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id,
            'session_minutes' => 30, 'booking_open' => true,
        ]))->save(false);
        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $this->frontDesk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
        $this->sunday = date('Y-m-d', strtotime($this->date . ' +6 days'));
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
    }

    public function consultantAddsAndRemovesASession(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->seeLink('Add or remove sessions');

        $I->amOnRoute('session/index');
        $I->submitForm('#session-form', [
            'ConsultantSession[facility_id]' => $this->avenue->id,
            'ConsultantSession[date]' => $this->sunday,
            'ConsultantSession[start_time]' => '09:00',
            'ConsultantSession[end_time]' => '12:00',
            'ConsultantSession[note]' => 'Outreach clinic',
        ]);
        $I->see('Session added');
        $I->see('Outreach clinic', 'table.sessions');
        $session = ConsultantSession::findOne(['consultant_id' => $this->consultant->id, 'date' => $this->sunday]);
        $I->assertNotNull($session);

        // It is bookable: patients see it in the calendar settings
        $I->amOnRoute('appointments/calendar');
        $I->seeInSource('"sessions":[{"date":"' . $this->sunday . '"');

        $I->amOnRoute('session/index');
        $I->submitForm('table.sessions form', []);
        $I->see('Session removed');
        $I->assertNull(ConsultantSession::findOne($session->id));
    }

    public function aSessionWithBookingsIsKept(FunctionalTester $I)
    {
        $session = new ConsultantSession(['consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id,
            'date' => $this->sunday, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
        $session->save(false);
        $appointment = $this->newBooking('10:00', null, $this->sunday);
        $I->assertTrue($appointment->saveBooking(), json_encode($appointment->errors));

        $I->amLoggedInAs($this->consultantUser);
        $I->sendCsrfPost(['/session/delete', 'id' => $session->id]);
        $I->assertNotNull(ConsultantSession::findOne($session->id));
    }

    public function frontDeskAddsSessionsAtTheirFacilityOnly(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('board/index');
        $I->seeLink('Sessions');

        $I->amOnRoute('session/index', ['cid' => $this->consultant->id]);
        $I->seeResponseCodeIs(200);
        $I->seeOptionIsSelected('ConsultantSession[facility_id]', 'Main clinic');
        $I->dontSee('Avenue Clinic', '#session-form');

        // A posted facility that is not theirs is refused
        $I->submitForm('#session-form', [
            'ConsultantSession[facility_id]' => $this->avenue->id,
            'ConsultantSession[date]' => $this->sunday,
            'ConsultantSession[start_time]' => '09:00',
            'ConsultantSession[end_time]' => '12:00',
        ]);
        $I->see('Choose one of the listed facilities.');
        $I->assertNull(ConsultantSession::findOne(['consultant_id' => $this->consultant->id]));

        $I->submitForm('#session-form', [
            'ConsultantSession[facility_id]' => $this->mainId,
            'ConsultantSession[date]' => $this->sunday,
            'ConsultantSession[start_time]' => '09:00',
            'ConsultantSession[end_time]' => '12:00',
        ]);
        $I->see('Session added');

        // Not for consultants they do not work for
        $other = new Consultant(['user_id' => $this->createUser('other.doctor', 'consultant')->id, 'names' => 'Dr. Other']);
        $other->save(false);
        $I->amOnRoute('session/index', ['cid' => $other->id]);
        $I->seeResponseCodeIs(403);
    }

    public function consultantSetsAnAlternateWeekRotation(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#weekly-schedule-form', [
            'rotation_week_a' => $this->date,
            'hours' => [
                1 => [
                    ['start' => '08:00', 'end' => '12:00', 'facility' => $this->mainId, 'weeks' => 'A', 'from' => '', 'to' => ''],
                    ['start' => '08:00', 'end' => '12:00', 'facility' => $this->avenue->id, 'weeks' => 'B', 'from' => '', 'to' => ''],
                ],
            ],
        ]);
        $I->see('Working hours saved.');
        $consultant = Consultant::findOne($this->consultant->id);
        $I->assertSame($this->date, $consultant->rotation_week_a);
        $I->assertSame('A', $consultant->getBlocks()[1][0][3]['weeks']);
        $I->see('(week B)');
    }

    public function consultantIsBusyElsewhere(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#external-form', [
            'ConsultantExternalBlock[place]' => 'Kenyatta National Hospital',
            'ConsultantExternalBlock[day_of_week]' => 7,
            'ConsultantExternalBlock[start_time]' => '09:00',
            'ConsultantExternalBlock[end_time]' => '13:00',
        ]);
        $I->see('Added: Sun 09:00–13:00 at Kenyatta National Hospital');
        $I->see('Kenyatta National Hospital', '.external-block');
        $I->assertNotNull(ConsultantExternalBlock::findOne(['consultant_id' => $this->consultant->id]));

        $I->submitForm('.external-block form', []);
        $I->assertNull(ConsultantExternalBlock::findOne(['consultant_id' => $this->consultant->id]));
    }

    public function consultantMakesAndStopsTheirCalendarFeed(FunctionalTester $I)
    {
        $this->book('09:00');
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#calendar-feed-form', []);
        $I->seeElement('#feed-url');
        $url = $I->grabTextFrom('#feed-url code');
        $I->assertMatchesRegularExpression('#/calendar/[A-Za-z0-9_-]{43}\.ics$#', $url);
        $token = basename($url, '.ics');

        // The address works without signing in
        $I->amLoggedInAs($this->client);
        \Yii::$app->user->logout();
        $I->amOnPage('/calendar/' . $token . '.ics');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('BEGIN:VCALENDAR');
        $I->seeInSource('SUMMARY:Appointment · Main clinic');
        $I->dontSeeInSource('Client');

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#calendar-feed-stop', []);
        $I->see('Calendar feed stopped.');
        $I->amOnPage('/calendar/' . $token . '.ics');
        $I->seeResponseCodeIs(404);
    }

    public function adminsCannotMakeAConsultantsFeed(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('availability/index', ['cid' => $this->consultant->id]);
        $I->dontSeeElement('#calendar-feed-form');
        $I->sendCsrfPost(['/availability/calendar-feed', 'cid' => $this->consultant->id]);
        $I->seeResponseCodeIs(403);
    }
}
