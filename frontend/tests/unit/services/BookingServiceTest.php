<?php

namespace frontend\tests\unit\services;

use common\models\User;
use frontend\models\Appointments;
use frontend\models\PublicHoliday;
use frontend\services\BookingService;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * The booking rules one by one, and the book/reschedule entry points. The timing rules run against
 * a fixed clock; the rest use the test consultant (default week: Mon-Sat 08-17, lunch 13-14).
 */
class BookingServiceTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** Monday 2 March 2026, 10:00 */
    private $now;

    protected function _before()
    {
        $this->now = mktime(10, 0, 0, 3, 2, 2026);
        $this->createParticipants();
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
    }

    private function service(array $config = [])
    {
        return new BookingService($config + ['now' => $this->now, 'minNoticeMinutes' => 60, 'maxDaysAhead' => 90]);
    }

    private function ts($date, $time)
    {
        return strtotime("$date $time");
    }

    // ---- 5-minute marks ----------------------------------------------------------------------

    public function testStartsMustFallOnFiveMinuteMarks()
    {
        $service = $this->service();

        $this->assertNull($service->startMarkProblem($this->ts('2026-03-03', '10:05')));
        $this->assertNull($service->startMarkProblem($this->ts('2026-03-03', '10:00')));
        $this->assertStringContainsString('such as 10:05', $service->startMarkProblem($this->ts('2026-03-03', '10:07')));
        $this->assertNotNull($service->startMarkProblem($this->ts('2026-03-03', '10:05:30')), 'seconds count too');
    }

    // ---- Timing: future, notice, window ------------------------------------------------------

    public function testMustBeInTheFuture()
    {
        $service = $this->service();

        $this->assertStringContainsString('in the future', $service->timingProblem($this->now));
        $this->assertStringContainsString('in the future', $service->timingProblem($this->now - 3600, true), 'even for the clinic');
    }

    public function testPatientsNeedNoticeButTheClinicDoesNot()
    {
        $service = $this->service();
        $soon = $this->now + 30 * 60;

        $this->assertStringContainsString('at least 1 hour', $service->timingProblem($soon));
        $this->assertNull($service->timingProblem($soon, true), 'walk-ins booked by the clinic');
        $this->assertNull($service->timingProblem($this->now + 60 * 60), 'exactly the notice');
    }

    public function testNoticeIsWorded()
    {
        $soon = $this->now + 5 * 60;

        $this->assertStringContainsString('2 hours', $this->service(['minNoticeMinutes' => 120])->timingProblem($soon));
        $this->assertStringContainsString('90 minutes', $this->service(['minNoticeMinutes' => 90])->timingProblem($soon));
        $this->assertNull($this->service(['minNoticeMinutes' => 0])->timingProblem($soon), 'no notice set');
    }

    public function testBookingWindow()
    {
        $service = $this->service();

        $this->assertNull($service->timingProblem($this->ts('2026-05-31', '16:00')), 'the 90th day');
        $this->assertStringContainsString('at most 90 days', $service->timingProblem($this->ts('2026-06-01', '08:00')));
        $this->assertStringContainsString('at most 90 days', $service->timingProblem($this->ts('2026-06-01', '08:00'), true), 'for the clinic too');
        $this->assertNull($this->service(['maxDaysAhead' => 0])->timingProblem($this->ts('2027-01-04', '10:00')), 'no window set');
    }

    // ---- Availability: deactivated, holidays, hours ------------------------------------------

    public function testOpeningHours()
    {
        $service = $this->service();
        $c = $this->consultant;

        $this->assertNull($service->availabilityProblem($c, $this->ts($this->date, '12:30'), $this->ts($this->date, '13:00')));
        $this->assertStringContainsString('outside Dr. Test', $service->availabilityProblem($c, $this->ts($this->date, '12:45'), $this->ts($this->date, '13:15')), 'runs into lunch');
        $sunday = date('Y-m-d', strtotime($this->date . ' -1 day'));
        $this->assertStringContainsString("doesn't see patients on Sundays", $service->availabilityProblem($c, $this->ts($sunday, '10:00'), $this->ts($sunday, '10:30')));
    }

    public function testPublicHolidaysCloseTheClinic()
    {
        (new PublicHoliday(['date' => $this->date, 'name' => 'Test Day']))->save(false);

        $problem = $this->service()->availabilityProblem($this->consultant, $this->ts($this->date, '10:00'), $this->ts($this->date, '10:30'));

        $this->assertStringContainsString('public holiday (Test Day)', $problem);
    }

    public function testDeactivatedConsultantTakesNoBookings()
    {
        $this->consultantUser->updateAttributes(['status' => User::STATUS_DELETED]);
        $this->consultant->refresh();

        $problem = $this->service()->availabilityProblem($this->consultant, $this->ts($this->date, '10:00'), $this->ts($this->date, '10:30'));

        $this->assertStringContainsString('no longer taking appointments', $problem);
    }

    // ---- Overlap -----------------------------------------------------------------------------

    public function testOverlapUsesEachAppointmentsOwnLength()
    {
        $existing = $this->book('10:00'); // 30 minutes
        $service = $this->service();
        $uid = $this->consultantUser->id;

        $this->assertNotNull($service->overlapProblem($uid, $this->ts($this->date, '10:15'), $this->ts($this->date, '10:45')));
        $this->assertNotNull($service->overlapProblem($uid, $this->ts($this->date, '09:45'), $this->ts($this->date, '10:15')));
        $this->assertNull($service->overlapProblem($uid, $this->ts($this->date, '10:30'), $this->ts($this->date, '11:00')), 'starts as it ends');
        $this->assertNull($service->overlapProblem($uid, $this->ts($this->date, '09:30'), $this->ts($this->date, '10:00')), 'ends as it starts');
        $this->assertNull($service->overlapProblem($uid, $this->ts($this->date, '10:00'), $this->ts($this->date, '10:30'), $existing->id), 'not with itself');

        $existing->updateAttributes(['status' => Appointments::STATUS_CANCELLED]);
        $this->assertNull($service->overlapProblem($uid, $this->ts($this->date, '10:00'), $this->ts($this->date, '10:30')), 'cancelled ones free the slot');
    }

    // ---- Entry points ------------------------------------------------------------------------

    public function testBookSettlesLengthAndTime()
    {
        $appointment = $this->newBooking('10:00');

        $this->assertTrue((new BookingService())->book($appointment), json_encode($appointment->errors));
        $this->assertSame('10:00:00', $appointment->time);
        $this->assertSame(30, (int) $appointment->duration_minutes);
    }

    public function testRescheduleKeepsTheBookedLength()
    {
        $appointment = $this->book('10:00');
        $this->setSessionMinutes(60);

        $this->assertTrue((new BookingService())->reschedule($appointment, $this->date, '11:00', true), json_encode($appointment->errors));

        $appointment->refresh();
        $this->assertSame('11:00:00', $appointment->time);
        $this->assertSame(30, (int) $appointment->duration_minutes);
    }

    public function testRescheduleMarksAPatientsOwnMove()
    {
        $appointment = $this->book('10:00');
        $service = new BookingService();

        $service->reschedule($appointment, $this->date, '11:00', true);
        $this->assertTrue($appointment->changedByPatient);
        $service->reschedule($appointment, $this->date, '11:30');
        $this->assertFalse($appointment->changedByPatient);
    }

    public function testCancelledAppointmentsCannotBeRescheduled()
    {
        $appointment = $this->book('10:00');
        $appointment->updateAttributes(['status' => Appointments::STATUS_CANCELLED]);

        $this->assertFalse((new BookingService())->reschedule($appointment, $this->date, '11:00'));
        $this->assertStringContainsString('cancelled', $appointment->getFirstError('time'));
    }

    public function testRescheduleIsCheckedLikeABooking()
    {
        $this->book('11:00', $this->otherClient);
        $appointment = $this->book('10:00');

        $this->assertFalse((new BookingService())->reschedule($appointment, $this->date, '11:00'));
        $this->assertSame('The doctor is not available at this time.', $appointment->getFirstError('time'));
    }
}
