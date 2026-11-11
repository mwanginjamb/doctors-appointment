<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\BookingLink;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\services\SelfServiceLink;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

/**
 * F8 phase 1: facilities, a consultant's engagements at them, and hours, booking links,
 * self-service, the calendar and messages at the right facility.
 */
class FacilitiesCest
{
    use BookingDataTrait;

    /** @var Facility the test consultant's first facility, made from their profile */
    private $main;
    /** @var Facility */
    private $avenue;

    public function _before(FunctionalTester $I)
    {
        \Yii::$app->cache->flush(); // per-IP rate limits on the public pages
        $this->createParticipants();
        $this->main = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility;
        $this->avenue = new Facility(['name' => 'Avenue Clinic', 'address' => 'Avenue House, Kenyatta Ave', 'phone' => '0720 000 000']);
        $this->avenue->save(false);
    }

    /** Engages the test consultant at Avenue Clinic: Mondays 08–13 at Main clinic, 14–17 at Avenue. */
    private function engageAtAvenue(array $attributes = [])
    {
        (new ConsultantFacility($attributes + [
            'consultant_id' => $this->consultant->id,
            'facility_id' => $this->avenue->id,
            'session_minutes' => 45,
            'fee' => 3500,
            'booking_open' => true,
        ]))->save(false);
        ConsultantWorkingHours::replaceWeek($this->consultant->id, [
            1 => [['08:00:00', '13:00:00', $this->main->id], ['14:00:00', '17:00:00', $this->avenue->id]],
        ]);
        $this->consultant = Consultant::findOne($this->consultant->id);
    }

    private function queued($class, array $contains = [])
    {
        $query = (new Query())->from('{{%queue}}')->where(['like', 'job', $class]);
        foreach ($contains as $text) {
            $query->andWhere(['like', 'job', $text]);
        }

        return (int) $query->count();
    }

    // ---- Admin: the facility list ------------------------------------------------------------

    public function adminAddsAFacility(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility/create');
        $I->submitForm('#facility-form', [
            'Facility[name]' => 'Nairobi Hospital',
            'Facility[address]' => 'Argwings Kodhek Rd',
            'Facility[phone]' => '0703 082 000',
            'Facility[map_url]' => 'maps.example.com/nh',
        ]);

        $I->see('Nairobi Hospital added.');
        $facility = Facility::findOne(['name' => 'Nairobi Hospital']);
        $I->assertSame('https://maps.example.com/nh', $facility->map_url);
        $I->assertTrue($facility->isActive());
    }

    public function onlyAdminsManageFacilities(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('facility/index');
        $I->seeResponseCodeIs(403);
    }

    public function aFacilityInUseIsKeptWhenDeleted(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility/index');
        $I->see('Dr. Test');
        $I->postAsLink(['/facility/delete', 'id' => $this->main->id]);

        $I->see('has consultants or appointments, so it is kept.');
        $I->assertNotNull(Facility::findOne($this->main->id));

        $I->postAsLink(['/facility/delete', 'id' => $this->avenue->id]);
        $I->see('Avenue Clinic removed.');
    }

    // ---- Consultant: engagements and hours ---------------------------------------------------

    public function consultantAddsAFacilityAndHoursThere(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->seeElement('#add-facility-form');
        $I->dontSeeElement('select[name="hours[1][0][facility]"]', [], 'one facility: no choice per range');

        $I->postAsLink(['/availability/add-facility'], [
            'ConsultantFacility' => ['facility_id' => $this->avenue->id, 'session_minutes' => '45', 'fee' => '3,500', 'booking_open' => '1'],
        ]);
        $I->see('Avenue Clinic added.');
        $engagement = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id]);
        $I->assertSame(45, (int) $engagement->session_minutes);
        $I->assertEquals(3500, $engagement->fee);

        // Two facilities: each range says where
        $I->seeElement('select[name="hours[1][0][facility]"]');
        $I->postAsLink(['/availability/index'], [
            'submitted' => 1,
            'hours' => [2 => [
                ['start' => '08:00', 'end' => '12:00', 'facility' => $this->main->id],
                ['start' => '14:00', 'end' => '18:00', 'facility' => $this->avenue->id],
            ]],
        ]);
        $I->see('Working hours saved.');
        $I->see('Avenue Clinic: Tue 14:00–18:00');
        $I->assertSame([2 => [['08:00:00', '12:00:00', $this->main->id], ['14:00:00', '18:00:00', $this->avenue->id]]], Consultant::findOne($this->consultant->id)->getBlocks());
    }

    public function aFacilityWithHoursOrTheOnlyOneCannotBeRemoved(FunctionalTester $I)
    {
        $only = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id]);
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/remove-facility', 'id' => $only->id]);
        $I->see('Main clinic is your only facility.');

        $this->engageAtAvenue();
        $avenue = ConsultantFacility::findOne(['consultant_id' => $this->consultant->id, 'facility_id' => $this->avenue->id]);
        $I->postAsLink(['/availability/remove-facility', 'id' => $avenue->id]);
        $I->see('Remove your weekly hours at Avenue Clinic first.');
        $I->assertNotNull(ConsultantFacility::findOne($avenue->id));
    }

    public function consultantsCannotChangeOtherConsultantsFacilities(FunctionalTester $I)
    {
        $other = $this->createUser('other.doctor', 'consultant');
        $profile = new Consultant(['user_id' => $other->id, 'names' => 'Dr. Other']);
        $profile->save(false);
        $theirs = ConsultantFacility::findOne(['consultant_id' => $profile->id]);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/update-facility', 'id' => $theirs->id], ['ConsultantFacility' => ['session_minutes' => '90']]);
        $I->seeResponseCodeIs(404);
        $I->assertNotSame(90, (int) ConsultantFacility::findOne($theirs->id)->session_minutes);
    }

    // ---- Calendar, appointment page and messages ---------------------------------------------

    public function theCalendarShowsEachFacilityInItsColour(FunctionalTester $I)
    {
        $this->engageAtAvenue();
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/calendar');

        $I->seeInSource('"facilityBlocks":[{');
        $I->seeInSource('"name":"Avenue Clinic"');
        $I->seeInSource('"duration":45');
        // 30 and 45 minutes: the grid steps by 15
        $I->seeInSource('"slotDuration":"00:15:00"');
        $I->seeElement('#facility-filter');
    }

    public function theAppointmentPageAndRemindersSayWhereItIs(FunctionalTester $I)
    {
        $this->engageAtAvenue();
        $appointment = $this->book('14:00');

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $appointment->id]);
        $I->see('Avenue Clinic', '.appointment-facility');
        $I->see('45 minutes · KES 3,500', '.appointment-facility');

        // Moved by the clinic: the patient is told where
        $before = $this->queued('SendWhatsappTextJob', ['Avenue House, Kenyatta Ave']);
        $I->assertTrue(\Yii::createObject(\frontend\services\BookingService::class)->reschedule($appointment, $this->date, '15:30:00'));
        $I->assertSame($before + 1, $this->queued('SendWhatsappTextJob', ['Avenue House, Kenyatta Ave']));
    }

    // ---- Booking links and self-service ------------------------------------------------------

    public function aBookingLinkOffersTheSlotsAtTheChosenFacility(FunctionalTester $I)
    {
        $this->engageAtAvenue();
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('booking-link/create');
        $I->seeElement('select[name="BookingLinkForm[facility_id]"]');
        $I->submitForm('#booking-link-form', [
            'BookingLinkForm[recipient_name]' => 'Wanjiru Remote',
            'BookingLinkForm[recipient_phone]' => '0733 222 111',
            'BookingLinkForm[facility_id]' => $this->avenue->id,
            'BookingLinkForm[from_date]' => $this->date,
            'BookingLinkForm[to_date]' => $this->date,
        ]);

        $I->see('Booking link sent');
        $link = BookingLink::findOne(['recipient_name' => 'Wanjiru Remote']);
        $I->assertSame($this->avenue->id, (int) $link->facility_id);
        $I->assertSame(['14:00', '14:45', '15:30', '16:15'], $link->openSlots()[$this->date]);
        $I->assertSame(1, $this->queued('SendWhatsappTextJob', ['Wanjiru Remote', 'Avenue Clinic']));
    }

    public function selfServiceOffersTimesAtTheSameFacilityOnly(FunctionalTester $I)
    {
        $this->engageAtAvenue();
        // Self-service needs the appointment more than 24 hours ahead: the fixture Monday is
        $appointment = $this->book('09:00');
        $I->amOnRoute('manage/index', ['id' => $appointment->id, 'sig' => SelfServiceLink::signature($appointment)]);

        $I->see('Main clinic');
        $I->seeInSource('"time":"10:00"');
        $I->dontSeeInSource('"time":"14:00"');
    }

    public function patientsCannotBookAFacilityClosedToOnlineBooking(FunctionalTester $I)
    {
        $this->engageAtAvenue(['booking_open' => false]);
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->seeInSource('"online":false');

        $response = $I->sendCsrfPost(['/api/visit'], json_encode([
            'date' => $this->date, 'time' => '14:00', 'consultant' => $this->consultantUser->id, 'brief' => 'Checkup',
        ]));
        $I->assertStringContainsString("doesn't take online bookings at Avenue Clinic", $response);
        $I->assertFalse(Appointments::find()->where(['patient_id' => $this->client->id])->exists());
    }
}
