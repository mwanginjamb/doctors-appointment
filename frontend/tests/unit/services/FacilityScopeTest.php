<?php

namespace frontend\tests\unit\services;

use common\components\RbacHelper as R;
use common\models\AuditLog;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\AppointmentBoardSearch;
use frontend\models\ClinicStats;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityClosure;
use frontend\models\FrontDeskAssignment;
use frontend\models\SlotFinder;
use frontend\services\BookingService;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F8 phase 2: front desk, patient history, closures, travel time and reporting per facility.
 *
 * Dr. Test works at Main clinic on Mondays 08:00–13:00 (30 minutes, KES 2,000) and at Avenue Clinic
 * 13:30–17:00 (30 minutes, KES 3,500). The front desk works at Main clinic for Dr. Test.
 */
class FacilityScopeTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var ConsultantFacility */
    private $main;
    /** @var ConsultantFacility */
    private $avenue;
    /** @var \common\models\User */
    private $frontDesk;

    protected function _before()
    {
        FrontDesk::reset();
        FacilityClosure::reset();
        $this->createParticipants();

        $this->main = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id]);
        $this->main->updateAttributes(['fee' => 2000]);
        $avenue = new Facility(['name' => 'Avenue Clinic']);
        $avenue->save(false);
        $this->avenue = new ConsultantFacility([
            'consultant_id' => $this->consultant->id,
            'facility_id' => $avenue->id,
            'session_minutes' => 30,
            'fee' => 3500,
            'booking_open' => true,
        ]);
        $this->avenue->save(false);
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [
            1 => [['08:00:00', '13:00:00', $this->main->facility_id], ['13:30:00', '17:00:00', $avenue->id]],
        ]);
        $this->reload();

        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $this->frontDesk->updateAttributes(['facility_id' => $this->main->facility_id]);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
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

    private function actAs($user)
    {
        FrontDesk::reset();
        Yii::$app->user->setIdentity($user);
    }

    private function can($user, $permission, array $params)
    {
        return Yii::$app->authManager->checkAccess($user->id, $permission, $params);
    }

    // ---- Front desk works at one facility --------------------------------------------------

    public function testFrontDeskHandlesOnlyAppointmentsAtTheirFacility()
    {
        $atMain = $this->book('09:00');
        $atAvenue = $this->book('14:00', $this->otherClient);

        foreach ([R::PERM_HANDLE_APPOINTMENT, R::PERM_RECORD_OUTCOME, R::PERM_BOOK_FOR_CONSULTANT] as $permission) {
            $this->assertTrue($this->can($this->frontDesk, $permission, ['appointment' => $atMain]), "$permission at their facility");
            $this->assertFalse($this->can($this->frontDesk, $permission, ['appointment' => $atAvenue]), "$permission elsewhere");
        }
        // The consultant and admins are not limited by facility
        $this->assertTrue($this->can($this->consultantUser, R::PERM_BOOK_FOR_CONSULTANT, ['appointment' => $atAvenue]));
        $this->assertTrue($this->can($this->admin, R::PERM_HANDLE_APPOINTMENT, ['appointment' => $atAvenue]));
    }

    public function testFrontDeskWithNoFacilitySetIsNotLimitedYet()
    {
        $this->frontDesk->updateAttributes(['facility_id' => null]);
        FrontDesk::reset();
        $atAvenue = $this->book('14:00');

        $this->assertTrue($this->can($this->frontDesk, R::PERM_HANDLE_APPOINTMENT, ['appointment' => $atAvenue]));
    }

    public function testFrontDeskBooksOnlyIntoTheirFacilitysHours()
    {
        $this->actAs($this->frontDesk);

        $elsewhere = $this->newBooking('14:00');
        $this->assertFalse((new BookingService())->book($elsewhere));
        $this->assertStringContainsString('You book only into the hours at Main clinic', $elsewhere->getFirstError('time'));

        $here = $this->newBooking('09:00');
        $this->assertTrue((new BookingService())->book($here), json_encode($here->errors));

        // ... and cannot move one there either
        $this->assertFalse((new BookingService())->reschedule($here, $this->date, '14:00'));
    }

    public function testTheBoardAndNumbersShowOnlyTheirFacility()
    {
        $this->book('09:00');
        $this->book('14:00', $this->otherClient);
        $this->actAs($this->frontDesk);

        $search = new AppointmentBoardSearch(['consultantUserIds' => FrontDesk::consultantUserIds(), 'facilityId' => FrontDesk::facilityId()]);
        $rows = $search->search(['from' => $this->date])->getModels();
        $this->assertCount(1, $rows);
        $this->assertSame('09:00:00', $rows[0]->time);

        $stats = new ClinicStats(['consultantUserIds' => FrontDesk::consultantUserIds(), 'facilityId' => FrontDesk::facilityId(), 'today' => $this->date]);
        $this->assertSame(1, $stats->getToday());

        // Admins see both, and can filter by facility
        $all = new AppointmentBoardSearch();
        $this->assertCount(2, $all->search(['from' => $this->date])->getModels());
        $this->assertCount(1, (new AppointmentBoardSearch())->search(['from' => $this->date, 'facility' => $this->avenue->facility_id])->getModels());
    }

    // ---- Patient history -------------------------------------------------------------------

    public function testNoShowCountsFollowWhoIsLooking()
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $other = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']);
        $other->save(false); // engaged at Main clinic, the default facility

        // The client missed one appointment with Dr. Test at Avenue, and one with Dr. Other at Main
        foreach ([[$this->consultantUser->id, $this->avenue->facility_id], [$otherDoctor->id, $this->main->facility_id]] as [$doctor, $facility]) {
            $missed = new Appointments([
                'date' => date('Y-m-d', strtotime('-7 days')), 'time' => '09:00:00', 'patient_id' => $this->client->id,
                'consultant_id' => $doctor, 'facility_id' => $facility, 'status' => Appointments::STATUS_NO_SHOW, 'duration_minutes' => 30,
            ]);
            $missed->save(false);
        }
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id, $other->id]);

        $count = function ($user) {
            $this->actAs($user);
            return Appointments::countNoShows($this->client->id, 365, FrontDesk::historyCondition());
        };
        $this->assertSame(2, $count($this->admin), 'admins see every facility');
        $this->assertSame(1, $count($this->consultantUser), "Dr. Test sees their own, at Avenue too, but not Dr. Other's");
        $this->assertSame(1, $count($this->frontDesk), "front desk at Main sees Main's only");
        $this->assertSame(0, $count($this->client), 'nobody else');
    }

    // ---- Facility closures -----------------------------------------------------------------

    public function testAClosedFacilityTakesNoBookingsThatDay()
    {
        (new FacilityClosure(['facility_id' => $this->avenue->facility_id, 'date' => $this->date, 'reason' => 'Staff training']))->save(false);
        $this->reload();

        $refused = $this->newBooking('14:00');
        $this->assertFalse($refused->saveBooking());
        $this->assertStringContainsString('Avenue Clinic is closed on', $refused->getFirstError('time'));
        $this->assertStringContainsString('Staff training', $refused->getFirstError('time'));

        // The consultant's hours at Main that day are not affected
        $this->assertTrue($this->newBooking('09:00')->saveBooking());

        $slots = (new SlotFinder(['consultant' => $this->reload(), 'from' => $this->date, 'to' => $this->date, 'notBefore' => strtotime($this->date . ' 00:00')]))->find();
        $this->assertContains('12:30', $slots[$this->date]);
        $this->assertNotContains('14:00', $slots[$this->date]);
    }

    public function testTheCalendarShadesTheClosedHoursOnly()
    {
        (new FacilityClosure(['facility_id' => $this->avenue->facility_id, 'date' => $this->date, 'reason' => 'Staff training']))->save(false);
        $calendar = new \frontend\models\CalendarViewModel(['viewer' => $this->consultantUser]);
        $closed = array_values(array_filter($calendar->blockedPeriods(), function ($p) {
            return strpos($p['title'], 'closed') !== false;
        }));

        $this->assertCount(1, $closed);
        $this->assertSame($this->date . 'T13:30:00', $closed[0]['start']);
        $this->assertSame($this->date . 'T17:00:00', $closed[0]['end']);
    }

    // ---- Travel time -----------------------------------------------------------------------

    public function testTravelTimeKeepsTheLastSlotsBeforeAMoveFree()
    {
        $this->consultant->updateAttributes(['travel_buffer_minutes' => 45]);
        $this->reload();

        // 12:30–13:00 leaves 30 minutes before Avenue opens at 13:30: not enough
        $tooLate = $this->newBooking('12:30');
        $this->assertFalse($tooLate->saveBooking());
        $this->assertStringContainsString('leaves for Avenue Clinic (from 13:30)', $tooLate->getFirstError('time'));
        // 12:00–12:30 leaves an hour
        $this->assertTrue($this->newBooking('12:00')->saveBooking());
        // The first slots at Avenue are not affected
        $this->assertTrue($this->newBooking('13:30', $this->otherClient)->saveBooking());

        $slots = (new SlotFinder(['consultant' => $this->reload(), 'from' => $this->date, 'to' => $this->date, 'notBefore' => strtotime($this->date . ' 00:00')]))->find();
        $this->assertNotContains('12:30', $slots[$this->date]);
        $this->assertContains('11:30', $slots[$this->date]);

        $travel = array_values(array_filter((new \frontend\models\CalendarViewModel(['viewer' => $this->consultantUser]))->blockedPeriods(), function ($p) {
            return strpos($p['title'], 'Travel to') === 0 && strpos($p['start'], $this->date) === 0;
        }));
        $this->assertCount(1, $travel);
        $this->assertSame($this->date . 'T12:45:00', $travel[0]['start']);
        $this->assertSame($this->date . 'T13:00:00', $travel[0]['end']);
    }

    public function testNoTravelTimeChangesNothing()
    {
        $this->assertTrue($this->newBooking('12:30')->saveBooking());
    }

    // ---- Reporting and audit ---------------------------------------------------------------

    public function testTheMonthlyReportSettlesUpPerFacility()
    {
        $rows = [
            [$this->main->facility_id, Appointments::STATUS_COMPLETED, 2000],
            [$this->main->facility_id, Appointments::STATUS_COMPLETED, 2000],
            [$this->main->facility_id, Appointments::STATUS_NO_SHOW, 2000],
            [$this->avenue->facility_id, Appointments::STATUS_COMPLETED, 3500],
            [$this->avenue->facility_id, Appointments::STATUS_CANCELLED, 3500],
            [$this->avenue->facility_id, Appointments::STATUS_COMPLETED, null],
        ];
        $day = date('Y-m-d', strtotime('-40 days'));
        foreach ($rows as $i => [$facility, $status, $fee]) {
            (new Appointments([
                'date' => $day, 'time' => sprintf('%02d:00:00', 8 + $i), 'patient_id' => $this->client->id,
                'consultant_id' => $this->consultantUser->id, 'facility_id' => $facility, 'status' => $status,
                'fee' => $fee, 'duration_minutes' => 30,
            ]))->save(false);
        }

        $report = (new ClinicStats())->getByFacility($day, $day);
        $byName = [];
        foreach ($report as $row) {
            $byName[$row['facility']->name] = $row;
        }
        $this->assertSame(3, $byName['Main clinic']['sessions']);
        $this->assertSame(2, $byName['Main clinic']['attended']);
        $this->assertSame(1, $byName['Main clinic']['noShows']);
        $this->assertEquals(4000, $byName['Main clinic']['fees']);
        $this->assertSame(2, $byName['Avenue Clinic']['sessions'], 'cancelled ones are not sessions');
        $this->assertSame(1, $byName['Avenue Clinic']['cancelled']);
        $this->assertEquals(3500, $byName['Avenue Clinic']['fees']);
        $this->assertSame(1, $byName['Avenue Clinic']['noFee']);

        $this->assertCount(1, (new ClinicStats(['facilityId' => $this->avenue->facility_id]))->getByFacility($day, $day));
    }

    public function testTheAuditLogRecordsWhereItHappened()
    {
        $appointment = $this->book('14:00');
        $entry = AuditLog::find()->where(['entity_type' => 'appointment', 'entity_id' => (string) $appointment->id, 'action' => AuditLog::ACTION_CREATED])->one();

        $this->assertNotNull($entry);
        $this->assertSame($this->avenue->facility_id, (int) $entry->facility_id);
    }
}
