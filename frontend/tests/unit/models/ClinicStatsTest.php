<?php

namespace frontend\tests\unit\models;

use frontend\models\Appointments;
use frontend\models\ClinicStats;
use frontend\models\Consultant;
use frontend\tests\BookingDataTrait;

class ClinicStatsTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var \frontend\tests\UnitTester */
    protected $tester;

    /** @var \common\models\User */
    private $otherDoctor;

    protected function _before()
    {
        $this->createParticipants();

        // On the fixture Monday: two active with Dr. Test and one cancelled; the next day one with Dr. Other
        $this->book('10:00');
        $this->book('11:00', $this->otherClient);
        $this->book('14:00')->cancel('Patient travelling');

        $this->otherDoctor = $this->createUser('other.doctor', 'consultant');
        (new Consultant(['user_id' => $this->otherDoctor->id, 'names' => 'Dr. Other']))->save(false);
        $tuesday = $this->newBooking('10:00', $this->otherClient, date('Y-m-d', strtotime($this->date . ' +1 day')));
        $tuesday->consultant_id = $this->otherDoctor->id;
        $tuesday->saveBooking();
    }

    private function stats(array $config = [])
    {
        return new ClinicStats($config + ['today' => $this->date]);
    }

    public function testCountsActiveAppointmentsTodayAndThisWeek()
    {
        $stats = $this->stats();

        $this->assertSame(2, $stats->getToday(), 'the cancelled one is not active');
        $this->assertSame(3, $stats->getNextSevenDays());
    }

    public function testCancellationRate()
    {
        // The window ends today (the fixture Monday), so Tuesday's appointment is not counted
        $this->assertSame(['total' => 3, 'cancelled' => 1, 'rate' => 33.3], $this->stats()->getCancellations());
        $this->assertSame(['total' => 0, 'cancelled' => 0, 'rate' => null], $this->stats(['today' => date('Y-m-d', strtotime($this->date . ' -40 days'))])->getCancellations());
    }

    public function testPerDayListsSevenDaysIncludingEmptyOnes()
    {
        $perDay = $this->stats()->getPerDay();

        $this->assertCount(7, $perDay);
        $this->assertSame($this->date, array_key_first($perDay));
        $this->assertSame([2, 1, 0, 0, 0, 0, 0], array_values($perDay));
    }

    public function testBusiestConsultantsComeFirst()
    {
        $busiest = $this->stats()->getBusiest();

        $this->assertSame(['Dr. Test', 'Dr. Other'], array_map(function ($row) {
            return $row['consultant']->names;
        }, $busiest));
        $this->assertSame([2, 1], array_column($busiest, 'count'));
    }

    public function testScopedToGivenConsultants()
    {
        $stats = $this->stats(['consultantUserIds' => [$this->otherDoctor->id]]);

        $this->assertSame(0, $stats->getToday());
        $this->assertSame(1, $stats->getNextSevenDays());
        $this->assertSame(['Dr. Other'], array_map(function ($row) {
            return $row['consultant']->names;
        }, $stats->getBusiest()));

        $this->assertSame(0, $this->stats(['consultantUserIds' => []])->getNextSevenDays(), 'no consultants, no numbers');
    }

    public function testBookedThisWeekSplitsOnlineFromStaff()
    {
        // Fixtures are saved with nobody signed in; say who booked each one
        $first = Appointments::find()->where(['time' => '10:00:00', 'date' => $this->date])->one();
        Appointments::updateAll(['created_by' => $first->patient_id], ['id' => $first->id]);
        Appointments::updateAll(['created_by' => $this->consultantUser->id], ['and', ['<>', 'id', $first->id]]);

        // "This week" counts from when the rows were created, i.e. now
        $booked = (new ClinicStats())->getBookedLastSevenDays();

        $this->assertSame(['total' => 4, 'online' => 1, 'staff' => 3], $booked);
    }
}
