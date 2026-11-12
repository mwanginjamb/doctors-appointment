<?php

namespace frontend\tests\unit\services;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\SlotFinder;
use frontend\models\WeeklyScheduleForm;
use frontend\services\BookingService;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F8: a consultant engaged at two facilities. On Mondays they are at Main clinic 08:00–13:00
 * (30 minutes, KES 2,000) and at Avenue Clinic 14:00–17:00 (45 minutes, KES 3,500).
 */
class FacilityBookingTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** How weekly hours that run every week, open-ended, look (F8 phase 3) */
    const EVERY_WEEK = ['weeks' => null, 'from' => null, 'to' => null];

    /** @var ConsultantFacility */
    private $main;
    /** @var ConsultantFacility */
    private $avenue;

    protected function _before()
    {
        $this->createParticipants();

        $this->main = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id]);
        $this->main->updateAttributes(['fee' => 2000]);

        $avenue = new Facility(['name' => 'Avenue Clinic', 'address' => "4th floor, Avenue House\nKenyatta Ave", 'phone' => '0720 000 000', 'map_url' => 'https://maps.example.com/avenue']);
        $avenue->save(false);
        $this->avenue = new ConsultantFacility([
            'consultant_id' => $this->consultant->id,
            'facility_id' => $avenue->id,
            'session_minutes' => 45,
            'fee' => 3500,
            'booking_open' => true,
        ]);
        $this->avenue->save(false);

        ConsultantWorkingHours::replaceWeek($this->consultant->id, [
            1 => [['08:00:00', '13:00:00', $this->main->facility_id], ['14:00:00', '17:00:00', $avenue->id]],
        ]);
        $this->consultant = Consultant::findOne($this->consultant->id);
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
    }

    private function reload()
    {
        $this->consultant = Consultant::findOne($this->consultant->id);
        return $this->consultant;
    }

    // ---- Facility, length and fee follow the time ------------------------------------------

    public function testABookingTakesTheFacilityLengthAndFeeOfItsBlock()
    {
        $morning = $this->book('09:00');
        $this->assertSame($this->main->facility_id, (int) $morning->facility_id);
        $this->assertSame(30, (int) $morning->duration_minutes);
        $this->assertEquals(2000, $morning->fee);

        $afternoon = $this->book('14:00');
        $this->assertSame($this->avenue->facility_id, (int) $afternoon->facility_id);
        $this->assertSame(45, (int) $afternoon->duration_minutes);
        $this->assertEquals(3500, $afternoon->fee);
        $this->assertSame('KES 3,500', $afternoon->getFeeLabel());
    }

    public function testASessionMustFitInsideOneFacilitysBlock()
    {
        $late = $this->newBooking('12:45'); // 30 minutes would run to 13:15
        $this->assertFalse($late->saveBooking());
        $this->assertStringContainsString('outside Dr. Test', $late->getFirstError('time'));

        $this->assertFalse($this->newBooking('16:30')->saveBooking(), '45 minutes at Avenue would run past 17:00');
        $this->assertTrue($this->newBooking('16:15')->saveBooking());
    }

    public function testMovingToAnotherFacilityTakesItsLengthAndFee()
    {
        $appointment = $this->book('09:00');
        $this->assertTrue((new BookingService())->reschedule($appointment, $this->date, '14:00'));

        $appointment = Appointments::findOne($appointment->id);
        $this->assertSame($this->avenue->facility_id, (int) $appointment->facility_id);
        $this->assertSame(45, (int) $appointment->duration_minutes);
        $this->assertEquals(3500, $appointment->fee);
    }

    public function testMovingWithinTheFacilityKeepsTheLengthAndFeeItWasBookedWith()
    {
        $appointment = $this->book('09:00');
        $this->main->updateAttributes(['session_minutes' => 60, 'fee' => 2500]);
        unset($appointment->consultant);

        $this->assertTrue((new BookingService())->reschedule($appointment, $this->date, '10:00'));
        $appointment = Appointments::findOne($appointment->id);
        $this->assertSame(30, (int) $appointment->duration_minutes);
        $this->assertEquals(2000, $appointment->fee);
    }

    public function testAppointmentsAtDifferentFacilitiesStillCannotOverlap()
    {
        // 14:00 at Avenue is taken; nothing at Main could be at 14:00 anyway, but the consultant is
        // one person: the overlap check spans facilities
        $this->book('14:00');
        $this->assertFalse($this->newBooking('14:00', $this->otherClient)->saveBooking());
    }

    // ---- Online booking, dates and closed facilities ----------------------------------------

    public function testPatientsCannotBookAFacilityClosedToOnlineBooking()
    {
        $this->avenue->updateAttributes(['booking_open' => false]);

        $appointment = $this->newBooking('14:00');
        $this->assertFalse($appointment->saveBooking());
        $this->assertStringContainsString("doesn't take online bookings at Avenue Clinic. Please call 0720 000 000", $appointment->getFirstError('time'));
        $this->assertTrue($this->newBooking('09:00')->saveBooking(), 'Main clinic is still open online');
    }

    public function testTheConsultantCanBookAFacilityClosedToOnlineBooking()
    {
        $this->avenue->updateAttributes(['booking_open' => false]);
        Yii::$app->user->setIdentity($this->consultantUser);

        $this->assertTrue($this->newBooking('14:00')->saveBooking());
    }

    public function testNoBookingsOutsideAnEngagementsDates()
    {
        $this->avenue->updateAttributes(['to_date' => date('Y-m-d', strtotime($this->date . ' -1 day'))]);

        $appointment = $this->newBooking('14:00');
        $this->assertFalse($appointment->saveBooking());
        $this->assertStringContainsString('outside Dr. Test', $appointment->getFirstError('time'));

        $this->avenue->updateAttributes(['to_date' => null, 'from_date' => date('Y-m-d', strtotime($this->date . ' +1 day'))]);
        $this->main->updateAttributes(['to_date' => date('Y-m-d', strtotime($this->date . ' -1 day'))]);
        $appointment = $this->newBooking('09:00');
        $this->assertFalse($appointment->saveBooking());
        $this->assertStringContainsString('is not seeing patients on', $appointment->getFirstError('time'));
    }

    public function testAClosedFacilityOffersNothing()
    {
        Facility::updateAll(['active' => false], ['id' => $this->avenue->facility_id]);

        $this->assertFalse($this->newBooking('14:00')->saveBooking());
        $this->assertSame([['08:00:00', '13:00:00', $this->main->facility_id, self::EVERY_WEEK]], $this->reload()->getRangesFor($this->date));
    }

    // ---- Free slots --------------------------------------------------------------------------

    public function testSlotsStepByEachFacilitysLength()
    {
        $slots = (new SlotFinder(['consultant' => $this->consultant, 'from' => $this->date, 'to' => $this->date]))->find();
        $this->assertSame(['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '14:00', '14:45', '15:30', '16:15'], $slots[$this->date]);

        $avenueOnly = (new SlotFinder(['consultant' => $this->consultant, 'from' => $this->date, 'to' => $this->date, 'facilityId' => $this->avenue->facility_id]))->find();
        $this->assertSame(['14:00', '14:45', '15:30', '16:15'], $avenueOnly[$this->date]);
    }

    public function testOnlineOnlySlotsLeaveOutFacilitiesClosedToOnlineBooking()
    {
        $this->avenue->updateAttributes(['booking_open' => false]);
        $slots = (new SlotFinder(['consultant' => $this->reload(), 'from' => $this->date, 'to' => $this->date, 'onlineOnly' => true]))->find();

        $this->assertSame('12:30', end($slots[$this->date]));
    }

    public function testABookingAtOneFacilityBlocksTheSameTimeEverywhere()
    {
        $this->book('14:00'); // Avenue, 45 minutes
        $slots = (new SlotFinder(['consultant' => $this->consultant, 'from' => $this->date, 'to' => $this->date]))->find();

        $this->assertNotContains('14:00', $slots[$this->date]);
        $this->assertContains('14:45', $slots[$this->date]);
    }

    // ---- Weekly hours ------------------------------------------------------------------------

    public function testWeeklyHoursCannotOverlapAcrossFacilities()
    {
        $form = new WeeklyScheduleForm($this->consultant);
        $form->load(['hours' => [2 => [
            ['start' => '08:00', 'end' => '12:00', 'facility' => $this->main->facility_id],
            ['start' => '11:00', 'end' => '15:00', 'facility' => $this->avenue->facility_id],
        ]]]);

        $this->assertFalse($form->save());
        $this->assertStringContainsString('overlap', $form->dayErrors[2][0]);
    }

    public function testWithSeveralFacilitiesEachRangeNeedsOne()
    {
        $form = new WeeklyScheduleForm($this->consultant);
        $form->load(['hours' => [2 => [['start' => '08:00', 'end' => '12:00']]]]);

        $this->assertFalse($form->save());
        $this->assertSame('Tuesday: choose where you are for 08:00–12:00.', $form->dayErrors[2][0]);
    }

    public function testWeeklyHoursAreSavedPerFacility()
    {
        $form = new WeeklyScheduleForm($this->consultant);
        $form->load(['hours' => [3 => [
            ['start' => '14:00', 'end' => '18:00', 'facility' => (string) $this->avenue->facility_id],
            ['start' => '08:00', 'end' => '12:00', 'facility' => (string) $this->main->facility_id],
        ]]]);

        $this->assertTrue($form->save());
        $this->assertSame([3 => [['08:00:00', '12:00:00', $this->main->facility_id, self::EVERY_WEEK], ['14:00:00', '18:00:00', $this->avenue->facility_id, self::EVERY_WEEK]]], $this->reload()->getBlocks());
        $this->assertSame('Avenue Clinic: Wed 14:00–18:00 | Main clinic: Wed 08:00–12:00', $this->consultant->getHoursSummary());
    }

    // ---- New consultants and messages ---------------------------------------------------------

    public function testANewConsultantStartsAtTheFacilityOnTheirProfile()
    {
        $user = $this->createUser('second.doctor', 'consultant');
        $consultant = new Consultant(['user_id' => $user->id, 'names' => 'Dr. Two', 'facility' => 'avenue clinic', 'appointment_session_duration' => 20]);
        $consultant->save(false);

        $engagements = Consultant::findOne($consultant->id)->engagements;
        $this->assertCount(1, $engagements);
        $this->assertSame($this->avenue->facility_id, (int) $engagements[0]->facility_id, 'matched by name, whatever the case');
        $this->assertSame(20, (int) $engagements[0]->session_minutes);
        $this->assertSame(ConsultantWorkingHours::DEFAULT_WEEK, Consultant::findOne($consultant->id)->getWeek());
    }

    public function testSpellingsOfOneFacilityAreMatched()
    {
        $this->assertSame('nairobi hospital', Facility::nameKey('The Nairobi Hospital.'));
        $this->assertSame(Facility::nameKey("St. Mary's Mission Hospital, Nairobi"), Facility::nameKey('st marys mission hospital nairobi'));

        // A profile naming a seeded hospital is engaged there, not at a copy
        $seeded = Facility::findOne(['name' => 'The Nairobi Hospital']);
        $this->assertNotNull($seeded, 'seeded by m261124_090000_seed_kenya_facilities');
        $this->assertSame(Facility::OWNERSHIP_PRIVATE, $seeded->ownership);
        $this->assertSame($seeded->id, Facility::findOrCreateByName('nairobi hospital')->id);

        $copy = new Facility(['name' => 'Nairobi Hospital']);
        $this->assertFalse($copy->validate());
        $this->assertSame('This is already listed as The Nairobi Hospital.', $copy->getFirstError('name'));
    }

    public function testMessagesSayWhereTheAppointmentIs()
    {
        $appointment = $this->book('14:00');
        $this->assertSame('Avenue Clinic, 4th floor, Avenue House, Kenyatta Ave. Map: https://maps.example.com/avenue', $appointment->getPlaceText());
    }
}
