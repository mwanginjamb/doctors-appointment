<?php

namespace frontend\tests\unit\models;

use common\rbac\AssignedConsultantRule;
use frontend\models\CalendarViewModel;
use frontend\models\ConsultantTimeOff;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * What the appointments calendar shows and allows, for each kind of viewer.
 */
class CalendarViewModelTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    protected function _before()
    {
        AssignedConsultantRule::reset();
        $this->createParticipants();
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
    }

    private function calendarFor($viewer, $consultant = null)
    {
        Yii::$app->user->setIdentity($viewer);

        return new CalendarViewModel(['viewer' => $viewer, 'consultant' => $consultant]);
    }

    public function testPatientBooksForThemselvesWithNotice()
    {
        $calendar = $this->calendarFor($this->client, $this->consultant);
        $config = $calendar->clientConfig();

        $this->assertSame('Consultantations Appointments for Client', $calendar->title);
        $this->assertFalse($calendar->readOnly);
        $this->assertFalse($config['booksForPatients']);
        $this->assertSame((int) Yii::$app->params['booking.minNoticeMinutes'], $config['noticeMinutes']);
    }

    public function testConsultantBooksWalkInsIntoTheirOwnCalendar()
    {
        $calendar = $this->calendarFor($this->consultantUser);

        $this->assertSame($this->consultant->id, $calendar->consultant->id, 'their own consultant record');
        $this->assertSame('Consultantations Schedule for Dr. Test', $calendar->title);
        $this->assertTrue($calendar->booksForPatients);
        $this->assertFalse($calendar->readOnly);
        $this->assertSame(0, $calendar->clientConfig()['noticeMinutes']);
    }

    public function testFrontDeskBooksForAssignedConsultant()
    {
        $desk = $this->createUser('front.desk', 'front_desk');
        FrontDeskAssignment::replaceFor($desk->id, [$this->consultant->id]);

        $calendar = $this->calendarFor($desk, $this->consultant);

        $this->assertSame('Front desk: Dr. Test', $calendar->title);
        $this->assertTrue($calendar->booksForPatients);
        $this->assertFalse($calendar->readOnly);
    }

    public function testAdminOnlyLooks()
    {
        $calendar = $this->calendarFor($this->admin, $this->consultant);

        $this->assertTrue($calendar->readOnly);
        $this->assertFalse($calendar->booksForPatients);
        $this->assertSame('Schedule for Dr. Test', $calendar->title);
        $this->assertFalse($calendar->needsConsultant());

        $this->assertTrue($this->calendarFor($this->admin)->needsConsultant(), 'staff must say whose calendar');
        $this->assertFalse($this->calendarFor($this->client)->needsConsultant(), 'patients have their own');
    }

    public function testDeactivatedConsultantsCalendarIsReadOnly()
    {
        $this->consultantUser->updateAttributes(['status' => \common\models\User::STATUS_DELETED]);
        $this->consultant->refresh();

        $calendar = $this->calendarFor($this->client, $this->consultant);

        $this->assertTrue($calendar->deactivated);
        $this->assertTrue($calendar->readOnly);
    }

    public function testAvailabilityFollowsTheWeek()
    {
        $availability = $this->calendarFor($this->client, $this->consultant)->availability();

        $this->assertContains(['daysOfWeek' => [1], 'startTime' => '08:00', 'endTime' => '13:00'], $availability['businessHours']);
        $this->assertSame([0], $availability['hiddenDays'], 'closed on Sundays');
        $this->assertSame('08:00', $availability['slotMinTime']);
        $this->assertSame('17:00', $availability['slotMaxTime']);
        $this->assertSame(30, $availability['duration']);
        $this->assertSame('00:30:00', $availability['slotDuration']);

        $this->assertNull($this->calendarFor($this->client)->availability(), 'no consultant');
    }

    public function testTimeOffReasonIsOnlyShownToTheConsultant()
    {
        (new ConsultantTimeOff([
            'consultant_id' => $this->consultant->id,
            'start_at' => $this->date . ' 09:00:00',
            'end_at' => $this->date . ' 11:00:00',
            'reason' => 'Private family matter',
        ]))->save(false);

        $patientSees = $this->calendarFor($this->client, $this->consultant)->blockedPeriods();
        $ownSees = $this->calendarFor($this->consultantUser)->blockedPeriods();

        $this->assertSame('Doctor away', $patientSees[0]['title']);
        $this->assertSame('Time off: Private family matter', $ownSees[0]['title']);
        $this->assertSame('background', $patientSees[0]['display']);
        $this->assertFalse($patientSees[0]['overlap']);
        $this->assertSame($this->date . 'T09:00:00', $patientSees[0]['start']);
    }
}
