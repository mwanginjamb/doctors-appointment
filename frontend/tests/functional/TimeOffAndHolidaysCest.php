<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantTimeOff;
use frontend\models\PublicHoliday;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class TimeOffAndHolidaysCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    public function consultantAddsLeaveAndSeesClashingAppointments(FunctionalTester $I)
    {
        $appointment = $this->book('10:00');
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#time-off-form', [
            'TimeOffForm[start_date]' => $this->date,
            'TimeOffForm[end_date]' => $this->date,
            'TimeOffForm[reason]' => 'Annual leave',
        ]);

        $I->see('Time off added');
        $I->see('1 appointment(s) are already booked during this time.');
        $I->see('Annual leave', '.time-off-entry');
        $I->seeLink('Client', '/index-test.php/appointments/view?id=' . $appointment->id);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($appointment->id)->status, 'clashes are kept');

        $saved = ConsultantTimeOff::findOne(['consultant_id' => $this->consultant->id]);
        $I->assertSame($this->date . ' 00:00:00', $saved->start_at);
        $I->assertSame(date('Y-m-d', strtotime($this->date . ' +1 day')) . ' 00:00:00', $saved->end_at);
    }

    public function invalidTimeOffIsRejected(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#time-off-form', [
            'TimeOffForm[start_date]' => $this->date,
            'TimeOffForm[start_time]' => '15:00',
            'TimeOffForm[end_date]' => $this->date,
            'TimeOffForm[end_time]' => '09:00',
        ]);

        $I->see('Time off must end after it starts.');
        $I->assertFalse(ConsultantTimeOff::find()->exists());
    }

    public function consultantRemovesOnlyTheirOwnTimeOff(FunctionalTester $I)
    {
        $mine = $this->timeOff($this->consultant);
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $otherConsultant = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']);
        $otherConsultant->save(false);
        $theirs = $this->timeOff($otherConsultant);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/delete-time-off', 'id' => $theirs->id]);
        $I->seeResponseCodeIs(404);
        $I->assertNotNull(ConsultantTimeOff::findOne($theirs->id));

        $I->amOnRoute('availability/index');
        $I->postAsLink(['/availability/delete-time-off', 'id' => $mine->id]);
        $I->see('Time off removed');
        $I->assertNull(ConsultantTimeOff::findOne($mine->id));
    }

    public function patientsCalendarShowsTheDoctorAwayButNotWhy(FunctionalTester $I)
    {
        $this->timeOff($this->consultant, 'Private family matter');
        (new PublicHoliday(['date' => date('Y-m-d', strtotime($this->date . ' +1 day')), 'name' => 'Test Day']))->save(false);

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->seeInSource('"title":"Doctor away"');
        $I->seeInSource('"title":"Public holiday: Test Day"');
        $I->dontSeeInSource('Private family matter');

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/calendar');
        $I->seeInSource('Time off: Private family matter');
    }

    public function shadedBlocksAreExplainedInsteadOfOpeningABrokenLink(FunctionalTester $I)
    {
        // Clicking used to open appointments/view?id= (no id) for doctor-away, holiday and booked blocks: a 404
        $this->timeOff($this->consultant);
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);

        $I->seeInSource('Js/booking-calendar.js');
        $I->assertStringContainsString('if (!info.event.id)', file_get_contents(\Yii::getAlias('@frontend/web/Js/booking-calendar.js')));
        $I->seeInSource('"reason":"Dr. Test is away');
        $I->dontSeeInSource('"blocked":[{"id"');

        $I->amOnRoute('appointments/my-calendar');
        $I->seeInSource('if (!info.event.id)');
    }

    public function adminAddsAHolidayAndIsWarnedAboutBookings(FunctionalTester $I)
    {
        $this->book('10:00');
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('holiday/create');
        $I->submitForm('.holiday-form form', [
            'PublicHoliday[date]' => $this->date,
            'PublicHoliday[name]' => 'Eid al-Fitr',
        ]);

        $I->see('Eid al-Fitr saved.');
        $I->see('1 appointment(s) are already booked on that date.');
        $I->assertNotNull(PublicHoliday::findOne(['date' => $this->date]));

        $I->amOnRoute('holiday/create');
        $I->submitForm('.holiday-form form', [
            'PublicHoliday[date]' => $this->date,
            'PublicHoliday[name]' => 'Duplicate',
        ]);
        $I->see('There is already a holiday on this date.');
    }

    public function onlyAdminsManageHolidays(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('holiday/index');
        $I->seeResponseCodeIs(403);
    }

    private function timeOff(Consultant $consultant, $reason = 'Leave')
    {
        $timeOff = new ConsultantTimeOff([
            'consultant_id' => $consultant->id,
            'start_at' => $this->date . ' 00:00:00',
            'end_at' => date('Y-m-d', strtotime($this->date . ' +1 day')) . ' 00:00:00',
            'reason' => $reason,
        ]);
        $timeOff->save(false);

        return $timeOff;
    }
}
