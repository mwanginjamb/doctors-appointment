<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\WaitlistEntry;
use frontend\services\SelfServiceLink;
use frontend\services\Waitlist;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

/**
 * F4 waiting list: patients join from a calendar, an appointment or their self-service link;
 * staff add patients; the patient's link books a freed time or leaves the list.
 */
class WaitlistCest
{
    use BookingDataTrait;

    /** @var string an open day at least 2 days ahead (Mon-Sat), outside the self-service cut-off */
    private $day;

    public function _before(FunctionalTester $I)
    {
        \Yii::$app->cache->flush(); // per-IP rate limit
        $this->createParticipants();
        $ts = strtotime('+2 days');
        if (date('N', $ts) === '7') {
            $ts = strtotime('+1 day', $ts);
        }
        $this->day = date('Y-m-d', $ts);
    }

    private function facilityId()
    {
        return (int) $this->consultant->engagements[0]->facility_id;
    }

    private function join($patient, $appointmentId = null)
    {
        return Waitlist::join([
            'patient_id' => $patient->id,
            'consultant_id' => $this->consultant->id,
            'facility_id' => $this->facilityId(),
            'from_date' => $this->day,
            'to_date' => $this->day,
            'day_start' => null,
            'day_end' => null,
            'appointment_id' => $appointmentId,
        ], $patient->id);
    }

    private function bookOn($time, $patient)
    {
        $appointment = $this->newBooking($time, $patient, $this->day);
        $appointment->saveBooking();

        return $appointment;
    }

    private function open(FunctionalTester $I, WaitlistEntry $entry, $sig = null)
    {
        $I->amOnRoute('waitlist/offer', ['id' => $entry->id, 'sig' => $sig ?? $entry->signature()]);
    }

    public function aPatientJoinsFromTheCalendar(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->seeElement('#waitlist-join-link');

        $I->amOnRoute('waitlist/join', ['cid' => $this->consultant->id]);
        $I->submitForm('#waitlist-form', [
            'WaitlistForm[from_date]' => $this->day,
            'WaitlistForm[to_date]' => date('Y-m-d', strtotime($this->day . ' +6 days')),
            'WaitlistForm[part_of_day]' => 'morning',
        ]);
        $I->see('You are on the waiting list');
        $I->see('Mornings');
        $I->seeElement('#waitlist-leave-form');

        $entry = WaitlistEntry::findOne(['patient_id' => $this->client->id]);
        $I->assertTrue($entry->isWaiting());
        $I->assertSame('12:00:00', $entry->day_end);

        $I->amOnRoute('waitlist/mine');
        $I->seeElement('#waitlist-entries');
        $I->see('Dr. Test');
    }

    public function theLinkBooksAFreedTime(FunctionalTester $I)
    {
        $taken = $this->bookOn('10:00', $this->otherClient);
        $entry = $this->join($this->client);
        $taken->cancel();

        $this->open($I, $entry);
        $I->see('Times free now');
        $I->seeInSource('"time":"10:00"');
        $I->submitForm('#waitlist-book-form', ['date' => $this->day, 'time' => '10:00']);

        $I->see('Booked: you have an appointment');
        $I->seeElement('#waitlist-booked');
        $appointment = Appointments::findOne(['patient_id' => $this->client->id, 'date' => $this->day, 'time' => '10:00:00']);
        $I->assertNotNull($appointment);
        $I->assertSame(WaitlistEntry::STATUS_BOOKED, WaitlistEntry::findOne($entry->id)->status);
    }

    public function aTakenTimeSaysSoAndOffersTheRest(FunctionalTester $I)
    {
        $taken = $this->bookOn('10:00', $this->otherClient);
        $entry = $this->join($this->client);
        $taken->cancel();
        $this->bookOn('10:00', $this->otherClient); // someone was quicker

        $this->open($I, $entry);
        $I->submitForm('#waitlist-book-form', ['date' => $this->day, 'time' => '10:00']);
        $I->see('that time has just been taken');
        $I->seeElement('#waitlist-book-form');
        $I->assertTrue(WaitlistEntry::findOne($entry->id)->isWaiting());
    }

    public function thePatientLeavesTheList(FunctionalTester $I)
    {
        $entry = $this->join($this->client);
        $this->open($I, $entry);
        $I->submitForm('#waitlist-leave-form', []);

        $I->see('You have left the waiting list');
        $I->seeElement('#waitlist-ended');
        $I->dontSeeElement('#waitlist-book-form');
        $I->assertSame(WaitlistEntry::STATUS_LEFT, WaitlistEntry::findOne($entry->id)->status);
    }

    public function aTamperedLinkIsRefused(FunctionalTester $I)
    {
        $entry = $this->join($this->client);
        $this->open($I, $entry, str_repeat('a', 32));
        $I->see('This waiting-list link is not valid');

        $I->amOnRoute('waitlist/leave', ['id' => $entry->id, 'sig' => $entry->signature()]);
        $I->seeResponseCodeIs(405); // POST only
    }

    public function earlierFromTheSelfServiceLink(FunctionalTester $I)
    {
        $appointment = $this->bookOn('16:00', $this->client);
        $I->amOnRoute('manage/index', ['id' => $appointment->id, 'sig' => SelfServiceLink::signature($appointment)]);
        $I->submitForm('#self-waitlist-form', []);

        $I->see('we will message you if an earlier time opens');
        $I->see('Earlier than');
        $entry = WaitlistEntry::findOne(['appointment_id' => $appointment->id]);
        $I->assertNotNull($entry);
        $I->assertSame((int) $this->client->id, (int) $entry->created_by);
    }

    public function earlierFromTheAppointmentPage(FunctionalTester $I)
    {
        $appointment = $this->bookOn('16:00', $this->client);
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $appointment->id]);
        $I->seeElement('#waitlist-earlier-link');

        $I->amOnRoute('waitlist/join', ['appointment' => $appointment->id]);
        $I->seeElement('#waitlist-earlier-note');
        $I->submitForm('#waitlist-form', ['WaitlistForm[part_of_day]' => 'any']);
        $I->see('You are on the waiting list');
        $I->assertNotNull(WaitlistEntry::findOne(['appointment_id' => $appointment->id]));

        // Someone else's appointment
        $other = $this->bookOn('15:00', $this->otherClient);
        $I->amOnRoute('waitlist/join', ['appointment' => $other->id]);
        $I->seeResponseCodeIs(404);
    }

    public function theConsultantAddsAPatientAndSeesTheList(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('waitlist/add');
        $fields = [
            'WaitlistForm[from_date]' => $this->day,
            'WaitlistForm[to_date]' => $this->day,
            'WaitlistForm[part_of_day]' => 'any',
        ];

        // Every test user shares one phone number
        $I->submitForm('#waitlist-form', $fields + ['WaitlistForm[patient_lookup]' => '0712 345 678']);
        $I->see('Several patients share this phone number');

        $before = (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])->andWhere(['like', 'job', 'on the waiting list'])->count();
        $I->submitForm('#waitlist-form', $fields + ['WaitlistForm[patient_lookup]' => 'other.client@example.com']);
        $I->see('Other Client is on the waiting list for Dr. Test');
        $I->seeElement('#waitlist-table');
        $after = (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])->andWhere(['like', 'job', 'on the waiting list'])->count();
        $I->assertSame($before + 1, $after, 'the patient is sent their link');

        $entry = WaitlistEntry::findOne(['patient_id' => $this->otherClient->id]);
        $I->assertSame((int) $this->consultantUser->id, (int) $entry->created_by);
        $I->submitForm('#waitlist-table form', []);
        $I->see('is off the waiting list');
        $I->assertSame(WaitlistEntry::STATUS_LEFT, WaitlistEntry::findOne($entry->id)->status);
    }

    public function aWalkInTakesTheNextSlotAndWaitsForAnEarlierOne(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/calendar');
        $I->seeElement('#appointment-waitlist');

        $response = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->day,
            'time' => '15:00:00',
            'brief' => 'Walk-in',
            'patient' => $this->client->id,
            'consultant' => $this->consultantUser->id,
            'waitlist' => true,
        ]);
        $I->assertSame('success', $response['status'], json_encode($response));
        $I->assertStringContainsString('on the waiting list for an earlier time', $response['message']);

        $appointment = Appointments::findOne(['patient_id' => $this->client->id, 'date' => $this->day]);
        $entry = WaitlistEntry::findOne(['appointment_id' => $appointment->id]);
        $I->assertNotNull($entry);
        $I->assertTrue($entry->isWaiting());
        $I->assertSame((int) $this->consultantUser->id, (int) $entry->created_by);
    }

    public function aSameDayWalkInIsToldAboutTimesSoonAfter(FunctionalTester $I)
    {
        // Today, in the afternoon: a walk-in booked for 16:30 waits for something earlier
        $now = strtotime(date('Y-m-d', strtotime($this->day)) . ' 13:50');
        Waitlist::$now = $now;
        \Yii::$app->params['waitlist.quietFrom'] = 0;
        \Yii::$app->params['waitlist.quietUntil'] = 0;
        try {
            $later = $this->bookOn('16:30', $this->client);
            $entry = $this->join($this->client, $later->id);
            $I->assertTrue(Waitlist::isSameDay($entry));
            // 14:00 is 10 minutes away: too soon. 14:30 is 40 minutes away: announced and bookable.
            $slots = $entry->openSlots(Waitlist::announceFrom($entry))[$this->day] ?? [];
            $I->assertNotContains('14:00', $slots);
            $I->assertContains('14:30', $slots);
        } finally {
            Waitlist::$now = null;
        }
    }

    public function patientsDoNotSeeTheStaffList(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('waitlist/index');
        $I->seeResponseCodeIs(403);
        $I->amOnRoute('waitlist/add');
        $I->seeResponseCodeIs(403);
    }
}
