<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantWorkingHours;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class AvailabilityCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    public function patientsCannotOpenTheAvailabilityPage(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('availability/index');
        $I->seeResponseCodeIs(403);
    }

    public function consultantSeesTheirDefaultWeek(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->seeResponseCodeIs(200);
        $I->see('Mon–Sat 08:00–13:00, 14:00–17:00');
        $I->seeElement('input[name="hours[1][0][start]"][value="08:00"]');
        $I->dontSeeElement('input[name^="hours[7]"]');
    }

    public function consultantSavesANewWeek(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/index'], [
            'submitted' => 1,
            'hours' => [
                3 => [['start' => '14:00', 'end' => '18:00'], ['start' => '09:00', 'end' => '12:00']],
                7 => [['start' => '10:00', 'end' => '12:00']],
                5 => [['start' => '', 'end' => '']], // an empty row is ignored
            ],
        ]);

        $I->see('Working hours saved.');
        $I->see('Wed 09:00–12:00, 14:00–18:00; Sun 10:00–12:00');
        $I->assertSame([
            3 => [['09:00:00', '12:00:00'], ['14:00:00', '18:00:00']],
            7 => [['10:00:00', '12:00:00']],
        ], Consultant::findOne($this->consultant->id)->getWeek());
    }

    public function closingEveryDayIsAllowedWithAWarning(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/index'], ['submitted' => 1]);

        $I->see('Every day is closed');
        $I->assertSame([], Consultant::findOne($this->consultant->id)->getWeek());
    }

    public function invalidRangesAreRejectedAndNothingChanges(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/index'], [
            'submitted' => 1,
            'hours' => [
                1 => [['start' => '08:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '15:00']],
                2 => [['start' => '17:00', 'end' => '09:00']],
                3 => [['start' => '9am', 'end' => '12:00']],
            ],
        ]);

        $I->see('Monday: 08:00–12:00 and 11:00–15:00 overlap.');
        $I->see('Tuesday: 17:00–09:00 must end after it starts.');
        $I->see('Wednesday: enter both times as HH:MM.');
        $I->assertSame(ConsultantWorkingHours::DEFAULT_WEEK, Consultant::findOne($this->consultant->id)->getWeek());
    }

    public function appointmentsOutsideTheNewHoursAreKeptWithAWarning(FunctionalTester $I)
    {
        $appointment = $this->book('10:00'); // a Monday
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/index'], [
            'submitted' => 1,
            'hours' => [1 => [['start' => '14:00', 'end' => '17:00']]],
        ]);

        $I->see('1 upcoming appointment(s) now fall outside your hours.');
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($appointment->id)->status);
    }

    public function calendarGreysOutClosedHoursAndHidesClosedDays(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);

        $I->seeInSource('"businessHours":[{"daysOfWeek":[1],"startTime":"08:00","endTime":"13:00"}');
        $I->seeInSource('"hiddenDays":[0]');
        $I->seeInSource('"noticeMinutes":60');
    }

    public function consultantSetsTheSessionLengthForNewBookings(FunctionalTester $I)
    {
        $booked = $this->book('10:00');
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->seeOptionIsSelected('#session-length', '30 minutes');

        $I->submitForm('#session-length-form', ['minutes' => '45']);

        $I->see('New bookings now last 45 minutes.');
        $I->assertSame(45, Consultant::findOne($this->consultant->id)->getSessionMinutes());
        $I->assertSame(30, (int) Appointments::findOne($booked->id)->duration_minutes, 'already booked: unchanged');

        $I->amOnRoute('appointments/calendar');
        $I->seeInSource('"slotDuration":"00:45:00"');
    }

    public function onlyListedSessionLengthsAreAccepted(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/session-length'], ['minutes' => '37']);

        $I->see('Choose one of the listed session lengths.');
        $I->assertSame(30, Consultant::findOne($this->consultant->id)->getSessionMinutes());
    }

    public function adminSetsAnotherConsultantsSessionLength(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('availability/index', ['cid' => $this->consultant->id]);
        $I->submitForm('#session-length-form', ['minutes' => '20']);

        $I->assertSame(20, Consultant::findOne($this->consultant->id)->getSessionMinutes());
        $I->seeInCurrentUrl('cid=' . $this->consultant->id);
    }

    public function calendarNoLongerOffersResizing(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/calendar');
        $I->seeInSource('Js/booking-calendar.js');
        $script = file_get_contents(\Yii::getAlias('@frontend/web/Js/booking-calendar.js'));
        $I->assertStringContainsString('eventDurationEditable: false', $script);
        $I->assertStringNotContainsString('eventResize', $script);
    }

    public function consultantBooksAWalkInWithoutNoticeButAPatientCannot(FunctionalTester $I)
    {
        $soon = (int) ceil((time() + 10 * 60) / 300) * 300; // starts fall on 5-minute marks
        if (date('H:i', $soon) > '23:15' || date('Y-m-d', $soon) !== date('Y-m-d')) {
            $I->markTestSkipped('Too close to midnight for a same-day walk-in slot.');
        }
        // Open all day, every day, so only the notice rule matters
        ConsultantWorkingHours::replaceWeek($this->consultant->id, array_fill(1, 7, [['00:00:00', '23:59:00']]));
        $slot = ['date' => date('Y-m-d', $soon), 'time' => date('H:i:00', $soon), 'brief' => 'Walk-in'];

        $I->amLoggedInAs($this->client);
        $patient = $I->sendJsonPost(['/api/visit'], $slot + ['consultant' => $this->consultantUser->id]);
        $I->assertSame('error', $patient['status']);
        $I->assertStringContainsString('1 hour in advance', $patient['message']);

        $I->amLoggedInAs($this->consultantUser);
        $walkIn = $I->sendJsonPost(['/api/visit'], $slot + ['patient' => $this->client->id]);
        $I->assertSame('success', $walkIn['status'], json_encode($walkIn));
    }
}
