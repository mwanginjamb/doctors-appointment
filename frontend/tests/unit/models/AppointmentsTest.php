<?php

namespace frontend\tests\unit\models;

use frontend\models\Appointments;
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

    public function testRejectsSlotOutsideDefaultWorkingHours()
    {
        $this->assertFalse($this->newBooking('08:30')->saveBooking());
        $this->assertFalse($this->newBooking('16:45')->saveBooking(), 'Slot ending after 17:00 must be rejected');
        $this->assertTrue($this->newBooking('16:30')->saveBooking());
    }

    public function testUsesConsultantWorkingHours()
    {
        $this->consultant->working_start_time = '07:00:00';
        $this->consultant->working_end_time = '12:00:00';
        $this->consultant->save(false);

        $this->assertTrue($this->newBooking('07:30')->saveBooking());
        $this->assertFalse($this->newBooking('12:00', $this->otherClient)->saveBooking());
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
