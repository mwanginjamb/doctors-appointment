<?php

namespace frontend\tests\unit\models;

use frontend\models\Appointments;
use frontend\models\ConsultantWorkingHours;
use frontend\tests\BookingDataTrait;

class AppointmentsTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /**
     * @var \frontend\tests\UnitTester
     */
    protected $tester;

    protected function _before()
    {
        $this->createParticipants();
    }

    public function testBooksFreeSlot()
    {
        $appointment = $this->newBooking('10:00');

        $this->assertTrue($appointment->saveBooking(), json_encode($appointment->errors));
        $this->assertSame('10:00:00', $appointment->time);
        $this->assertSame(Appointments::STATUS_SCHEDULED, $appointment->status);
    }

    public function testRejectsSlotStartingInsideExistingAppointment()
    {
        $this->book('10:00');

        $appointment = $this->newBooking('10:15', $this->otherClient);

        $this->assertFalse($appointment->saveBooking());
        $this->assertArrayHasKey('time', $appointment->errors);
    }

    public function testRejectsSlotThatRunsIntoExistingAppointment()
    {
        $this->book('10:15');

        $this->assertFalse($this->newBooking('10:00', $this->otherClient)->saveBooking());
    }

    public function testAllowsBackToBackAppointments()
    {
        $this->book('10:00');

        $this->assertTrue($this->newBooking('10:30', $this->otherClient)->saveBooking());
        $this->assertTrue($this->newBooking('09:30', $this->otherClient)->saveBooking());
    }

    public function testCancelledAppointmentDoesNotBlockSlot()
    {
        $existing = $this->book('10:00');
        $existing->status = Appointments::STATUS_CANCELLED;
        $existing->save(false);

        $this->assertTrue($this->newBooking('10:00', $this->otherClient)->saveBooking());
    }

    public function testOtherConsultantsAppointmentsDoNotBlockSlot()
    {
        $this->book('10:00');
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        (new \frontend\models\Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']))->save(false);

        $appointment = $this->newBooking('10:00', $this->otherClient);
        $appointment->consultant_id = $otherDoctor->id;

        $this->assertTrue($appointment->saveBooking(), json_encode($appointment->errors));
    }

    public function testDefaultWeekIsMondayToSaturdayWithALunchBreak()
    {
        $this->assertFalse($this->newBooking('07:30')->saveBooking(), 'before 08:00');
        $this->assertFalse($this->newBooking('12:45')->saveBooking(), 'runs into the 13:00-14:00 break');
        $this->assertFalse($this->newBooking('13:30')->saveBooking(), 'inside the break');
        $this->assertFalse($this->newBooking('16:45')->saveBooking(), 'ends after 17:00');
        $this->assertTrue($this->newBooking('08:00')->saveBooking());
        $this->assertTrue($this->newBooking('12:30', $this->otherClient)->saveBooking(), 'ends exactly at 13:00');
        $this->assertTrue($this->newBooking('14:00')->saveBooking());

        $saturday = date('Y-m-d', strtotime($this->date . ' +5 days'));
        $sunday = date('Y-m-d', strtotime($this->date . ' +6 days'));
        $this->assertTrue($this->newBooking('10:00', null, $saturday)->saveBooking());
        $closed = $this->newBooking('10:00', null, $sunday);
        $this->assertFalse($closed->saveBooking());
        $this->assertStringContainsString('Sundays', $closed->getFirstError('time'));
    }

    public function testUsesConsultantsWeeklySchedule()
    {
        // Mondays 07:00-12:00 only
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [1 => [['07:00:00', '12:00:00']]]);

        $this->assertTrue($this->newBooking('07:30')->saveBooking());
        $late = $this->newBooking('12:00', $this->otherClient);
        $this->assertFalse($late->saveBooking());
        $this->assertStringContainsString('07:00–12:00', $late->getFirstError('time'));

        $tuesday = date('Y-m-d', strtotime($this->date . ' +1 day'));
        $this->assertFalse($this->newBooking('10:00', null, $tuesday)->saveBooking(), 'Tuesday is now closed');
    }

    public function testConsultantWithNoHoursCannotBeBooked()
    {
        ConsultantWorkingHours::replaceWeek($this->consultant->id, []);

        $this->assertFalse($this->newBooking('10:00')->saveBooking());
    }

    public function testNewConsultantGetsTheDefaultWeek()
    {
        $this->assertSame(ConsultantWorkingHours::DEFAULT_WEEK, $this->consultant->getWeek());
        $this->assertSame('Mon–Sat 08:00–13:00, 14:00–17:00', $this->consultant->getHoursSummary());
    }

    public function testSummaryGroupsConsecutiveDaysWithTheSameHours()
    {
        $this->assertSame(
            'Mon–Tue 09:00–12:00; Thu 09:00–12:00; Sat 10:00–14:00',
            ConsultantWorkingHours::summarize([
                1 => [['09:00:00', '12:00:00']],
                2 => [['09:00:00', '12:00:00']],
                4 => [['09:00:00', '12:00:00']],
                6 => [['10:00:00', '14:00:00']],
            ])
        );
        $this->assertNull(ConsultantWorkingHours::summarize([]));
    }

    public function testPatientsNeedAnHoursNotice()
    {
        $soon = time() + 30 * 60;
        $appointment = $this->newBooking(date('H:i', $soon), null, date('Y-m-d', $soon));

        $this->assertFalse($appointment->saveBooking());
        $this->assertStringContainsString('1 hour in advance', $appointment->getFirstError('time'));
    }

    public function testCannotBookMoreThanNinetyDaysAhead()
    {
        // The Monday 91-97 days ahead: open, but past the window
        $tooFar = date('Y-m-d', strtotime('monday', strtotime('+91 days')));
        $appointment = $this->newBooking('10:00', null, $tooFar);

        $this->assertFalse($appointment->saveBooking());
        $this->assertStringContainsString('90 days', $appointment->getFirstError('time'));
    }

    public function testRejectsPastSlot()
    {
        $appointment = $this->newBooking('10:00', null, date('Y-m-d', strtotime('-1 day')));

        $this->assertFalse($appointment->saveBooking());
    }

    public function testReschedulingDoesNotConflictWithItself()
    {
        $appointment = $this->book('10:00');
        $appointment->time = '10:15';

        $this->assertTrue($appointment->saveBooking(), json_encode($appointment->errors));
    }

    public function testProtectedAttributesAreNotMassAssigned()
    {
        $appointment = $this->newBooking('10:00');
        $appointment->load(['Appointments' => [
            'patient_id' => $this->otherClient->id,
            'consultant_id' => 999,
            'status' => Appointments::STATUS_COMPLETED,
            'created_by' => $this->otherClient->id,
            'symptoms_brief' => 'Fever',
        ]]);

        $this->assertSame($this->client->id, $appointment->patient_id);
        $this->assertSame($this->consultantUser->id, $appointment->consultant_id);
        $this->assertNull($appointment->status);
        $this->assertNull($appointment->created_by);
        $this->assertSame('Fever', $appointment->symptoms_brief);
    }

    public function testConfirmedAppointmentCanBeSaved()
    {
        $appointment = $this->book('10:00');
        $appointment->status = Appointments::STATUS_CONFIRMED;
        $appointment->symptoms_brief = 'Updated';

        $this->assertTrue($appointment->save(), json_encode($appointment->errors));
    }

    public function testParticipantChecks()
    {
        $appointment = $this->book('10:00');

        $this->assertTrue($appointment->isParticipant($this->client->id));
        $this->assertTrue($appointment->isParticipant($this->consultantUser->id));
        $this->assertFalse($appointment->isParticipant($this->otherClient->id));
        $this->assertTrue($appointment->isConsultant($this->consultantUser->id));
        $this->assertFalse($appointment->isConsultant($this->client->id));
    }
}
