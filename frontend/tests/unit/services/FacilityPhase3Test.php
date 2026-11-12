<?php

namespace frontend\tests\unit\services;

use common\components\RbacHelper as R;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\CalendarFeed;
use frontend\models\Consultant;
use frontend\models\ConsultantExternalBlock;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantSession;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityClosure;
use frontend\models\FrontDeskAssignment;
use frontend\models\PublicHoliday;
use frontend\models\SlotFinder;
use frontend\models\WeeklyScheduleForm;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F8 phase 3: rotations, one-off sessions, external engagements and the calendar feed.
 *
 * Dr. Test works at Main clinic and Avenue Clinic. $this->date (a Monday) is in week A.
 */
class FacilityPhase3Test extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var int */
    private $mainId;
    /** @var Facility */
    private $avenue;
    /** @var string the Monday after $this->date: week B */
    private $weekB;

    protected function _before()
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
        $this->consultant->updateAttributes(['rotation_week_a' => $this->date]);
        $this->weekB = date('Y-m-d', strtotime($this->date . ' +7 days'));
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
        FrontDesk::reset();
        FacilityClosure::reset();
    }

    private function reload()
    {
        $this->consultant = Consultant::findOne($this->consultant->id);
        return $this->consultant;
    }

    private function bookOn($date, $time, $patient = null)
    {
        $appointment = $this->newBooking($time, $patient, $date);
        $ok = $appointment->saveBooking();

        return [$ok, $appointment];
    }

    private function slots($date)
    {
        return (new SlotFinder(['consultant' => $this->reload(), 'from' => $date, 'to' => $date, 'notBefore' => strtotime($date . ' 00:00')]))->find()[$date];
    }

    // ---- Rotations -----------------------------------------------------------------------------

    public function testAlternateWeeksRunOnlyInTheirWeek()
    {
        // Mondays: Main clinic in week A, Avenue Clinic in week B, same hours
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [1 => [
            ['08:00:00', '12:00:00', $this->mainId, ['weeks' => 'A']],
            ['08:00:00', '12:00:00', $this->avenue->id, ['weeks' => 'B']],
        ]]);
        $this->reload();

        $this->assertSame('A', $this->consultant->weekLetter($this->date));
        $this->assertSame('B', $this->consultant->weekLetter($this->weekB));
        $this->assertSame('A', $this->consultant->weekLetter(date('Y-m-d', strtotime($this->date . ' +16 days'))), 'the Wednesday two weeks on');

        [$ok, $a] = $this->bookOn($this->date, '09:00');
        $this->assertTrue($ok, json_encode($a->errors));
        $this->assertSame($this->mainId, (int) $a->facility_id);

        [$ok, $b] = $this->bookOn($this->weekB, '09:00');
        $this->assertTrue($ok, json_encode($b->errors));
        $this->assertSame($this->avenue->id, (int) $b->facility_id, 'week B is at Avenue');
        $this->assertEquals(3500, $b->fee);
    }

    public function testAWeekAOnlyRangeIsClosedInWeekB()
    {
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [1 => [['08:00:00', '12:00:00', $this->mainId, ['weeks' => 'A']]]]);

        [$ok, $appointment] = $this->bookOn($this->weekB, '09:00');
        $this->assertFalse($ok);
        $this->assertStringContainsString('not seeing patients on', $appointment->getFirstError('time'));
        $this->assertSame([], $this->slots($this->weekB));
        $this->assertContains('09:00', $this->slots($this->date));
    }

    public function testHoursCanStartOnADate()
    {
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [1 => [['08:00:00', '12:00:00', $this->mainId, ['from' => $this->weekB]]]]);

        $this->assertFalse($this->bookOn($this->date, '09:00')[0], 'before its first day');
        $this->assertTrue($this->bookOn($this->weekB, '09:00')[0]);
    }

    public function testTheWeeklyFormKnowsWhichRangesCanMeet()
    {
        $form = function (array $mondays, $weekA = '2026-11-30') {
            $form = new WeeklyScheduleForm($this->reload());
            $form->load(['submitted' => 1, 'rotation_week_a' => $weekA, 'hours' => [1 => $mondays]]);
            return $form;
        };
        $range = function ($facility, $weeks = '', $from = '', $to = '') {
            return ['start' => '08:00', 'end' => '12:00', 'facility' => $facility, 'weeks' => $weeks, 'from' => $from, 'to' => $to];
        };

        $this->assertTrue($form([$range($this->mainId, 'A'), $range($this->avenue->id, 'B')])->validate(), 'A and B never meet');
        $this->assertFalse($form([$range($this->mainId, 'A'), $range($this->avenue->id)])->validate(), 'A and every week do');
        $this->assertTrue($form([$range($this->mainId, '', '', '2026-12-31'), $range($this->avenue->id, '', '2027-01-01')])->validate(), 'one ends before the other starts');
        $this->assertFalse($form([$range($this->mainId, '', '', '2027-01-15'), $range($this->avenue->id, '', '2027-01-01')])->validate(), 'their dates overlap');

        $noAnchor = $form([$range($this->mainId, 'A')], '');
        $this->assertFalse($noAnchor->validate());
        $this->assertStringContainsString('choose a date in a week A', $noAnchor->dayErrors[0][0]);

        // Saved: the anchor becomes that week's Monday
        $saved = $form([$range($this->mainId, 'A'), $range($this->avenue->id, 'B')], '2026-12-03');
        $this->assertTrue($saved->save());
        $this->assertSame('2026-11-30', $this->reload()->rotation_week_a);
        $this->assertSame(['weeks' => 'B', 'from' => null, 'to' => null], $this->consultant->getBlocks()[1][1][3]);
        $this->assertStringContainsString('(week A)', $this->consultant->getHoursSummary());
    }

    // ---- One-off sessions ----------------------------------------------------------------------

    private function session($date, $from, $to, $facilityId = null)
    {
        $session = new ConsultantSession([
            'consultant_id' => $this->consultant->id, 'facility_id' => $facilityId ?? $this->avenue->id,
            'date' => $date, 'start_time' => $from, 'end_time' => $to, 'note' => 'Outreach',
        ]);
        $ok = $session->save();

        return [$ok, $session];
    }

    public function testASessionIsBookableLikeWeeklyHours()
    {
        // The default week is Monday to Saturday: a Sunday outreach clinic
        $sunday = date('Y-m-d', strtotime($this->date . ' +6 days'));
        [$ok, $session] = $this->session($sunday, '09:00', '12:00');
        $this->assertTrue($ok, json_encode($session->errors));
        $this->reload();

        [$booked, $appointment] = $this->bookOn($sunday, '10:00');
        $this->assertTrue($booked, json_encode($appointment->errors));
        $this->assertSame($this->avenue->id, (int) $appointment->facility_id);
        $this->assertEquals(3500, $appointment->fee);
        $this->assertContains('09:00', $this->slots($sunday));
        $this->assertSame(1, (int) $session->bookedAppointments()->count());
    }

    public function testASessionCannotOverlapOtherHoursHolidaysOrClosures()
    {
        // Default week: Monday 08:00–13:00 and 14:00–17:00 at Main clinic
        [$ok, $session] = $this->session($this->date, '12:00', '15:00');
        $this->assertFalse($ok);
        $this->assertStringContainsString("overlaps Dr. Test's hours at Main clinic", $session->getFirstError('start_time'));
        $this->assertTrue($this->session($this->date, '17:00', '19:00')[0], 'after the weekly hours');

        $saturday = date('Y-m-d', strtotime($this->date . ' +5 days'));
        (new PublicHoliday(['date' => $saturday, 'name' => 'Jamhuri Day']))->save(false);
        $this->assertStringContainsString('public holiday', $this->session($saturday, '09:00', '12:00')[1]->getFirstError('date'));

        $sunday = date('Y-m-d', strtotime($this->date . ' +6 days'));
        (new FacilityClosure(['facility_id' => $this->avenue->id, 'date' => $sunday, 'reason' => 'Painting']))->save(false);
        $this->assertStringContainsString('Avenue Clinic is closed', $this->session($sunday, '09:00', '12:00')[1]->getFirstError('date'));

        $this->assertNotEmpty($this->session(date('Y-m-d', strtotime('-1 day')), '09:00', '12:00')[1]->getErrors('date'), 'not in the past');
    }

    public function testWeeklyHoursCannotBeMovedOntoASession()
    {
        $this->assertTrue($this->session($this->date, '17:00', '19:00')[0]);
        $form = new WeeklyScheduleForm($this->reload());
        $form->load(['submitted' => 1, 'hours' => [1 => [['start' => '16:00', 'end' => '18:00', 'facility' => $this->mainId]]]]);

        $this->assertFalse($form->validate());
        $this->assertStringContainsString('overlaps your session', $form->dayErrors[1][0]);
    }

    public function testWhoMayManageSessions()
    {
        $main = ['consultant' => $this->consultant, 'facilityId' => $this->mainId];
        $avenue = ['consultant' => $this->consultant, 'facilityId' => $this->avenue->id];
        $can = function ($user, $params) {
            return Yii::$app->authManager->checkAccess($user->id, R::PERM_MANAGE_SESSIONS, $params);
        };

        $frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $frontDesk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($frontDesk->id, [$this->consultant->id]);
        $facilityAdmin = $this->createUser('avenue.admin', User::ACCOUNT_FACILITY_ADMIN);
        $facilityAdmin->updateAttributes(['facility_id' => $this->avenue->id]);
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        FrontDesk::reset();

        $this->assertTrue($can($this->consultantUser, $avenue), 'their own');
        $this->assertTrue($can($frontDesk, $main), 'front desk at their facility');
        $this->assertFalse($can($frontDesk, $avenue), 'front desk elsewhere');
        $this->assertTrue($can($facilityAdmin, $avenue), 'facility admin at theirs');
        $this->assertFalse($can($facilityAdmin, $main), 'facility admin elsewhere');
        $this->assertTrue($can($this->admin, $main), 'consultancy admins');
        $this->assertFalse($can($otherDoctor, $main), "another consultant's");
        $this->assertFalse($can($this->client, $main), 'patients');
    }

    public function testTheConsultantIsToldWhenSomeoneElseAddsOne()
    {
        $frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        Yii::$app->user->setIdentity($frontDesk);
        [, $session] = $this->session(date('Y-m-d', strtotime($this->date . ' +6 days')), '09:00', '12:00');
        $this->assertSame(['email', 'whatsapp'], $session->notifyConsultant());

        Yii::$app->user->setIdentity($this->consultantUser);
        $this->assertSame([], $session->notifyConsultant(), 'not when they did it themselves');
    }

    // ---- External engagements ------------------------------------------------------------------

    public function testHoursElsewhereCannotBeBooked()
    {
        // Monday afternoon at Main is 14:00–17:00 by default: move it, and be at KNH 14:00–17:00
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [1 => [['08:00:00', '13:00:00', $this->mainId]]]);
        $external = new ConsultantExternalBlock(['consultant_id' => $this->consultant->id, 'place' => 'Kenyatta National Hospital',
            'day_of_week' => 1, 'start_time' => '14:00', 'end_time' => '17:00']);
        $this->assertTrue($external->save(), json_encode($external->errors));
        $this->reload();

        $this->assertSame([['14:00:00', '17:00:00', 'Kenyatta National Hospital']], $this->consultant->externalOn($this->date));
        $this->assertTrue((new \frontend\services\BookingService())->availabilityProblem($this->consultant, strtotime($this->date . ' 14:30'), strtotime($this->date . ' 15:00')) !== null);
        $this->assertStringContainsString('busy at Kenyatta National Hospital', (new \frontend\services\BookingService())->availabilityProblem($this->consultant, strtotime($this->date . ' 14:30'), strtotime($this->date . ' 15:00')));

        // It may not overlap hours at a facility in the app
        $clash = new ConsultantExternalBlock(['consultant_id' => $this->consultant->id, 'place' => 'KNH', 'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:30']);
        $this->assertFalse($clash->save());
        $this->assertStringContainsString('overlaps your hours at Main clinic', $clash->getFirstError('start_time'));

        $busy = array_values(array_filter((new \frontend\models\CalendarViewModel(['viewer' => $this->consultantUser]))->blockedPeriods(), function ($p) {
            return strpos($p['title'], 'Busy at') === 0 && strpos($p['start'], $this->date) === 0;
        }));
        $this->assertCount(1, $busy);
        $this->assertSame('Busy at Kenyatta National Hospital', $busy[0]['title']);
    }

    // ---- Calendar feed -------------------------------------------------------------------------

    public function testTheFeedShowsWhenAndWhereButNoPatient()
    {
        $appointment = $this->book('09:00');
        $url = CalendarFeed::regenerate($this->consultant);
        $this->assertMatchesRegularExpression('#/calendar/[A-Za-z0-9_-]{43}\.ics$#', $url);
        $token = basename($url, '.ics');

        $consultant = CalendarFeed::findConsultant($token);
        $this->assertSame($this->consultant->id, $consultant->id);
        $this->assertNull(CalendarFeed::findConsultant(str_repeat('a', 43)));

        $ics = (new CalendarFeed(['consultant' => $consultant]))->render();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('UID:appointment-' . $appointment->id . '@', $ics);
        $this->assertStringContainsString('SUMMARY:Appointment · Main clinic', $ics);
        $this->assertStringNotContainsString('Client', $ics, 'no patient name');
        $this->assertStringNotContainsString('Headache', $ics, 'no visit reason');

        CalendarFeed::revoke($consultant);
        $this->assertNull(CalendarFeed::findConsultant($token));
    }
}
