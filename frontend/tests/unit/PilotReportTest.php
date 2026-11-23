<?php

namespace frontend\tests\unit;

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\services\PilotReport;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F26: the 90-day pilot report: months counted from each consultant's own first booking and by the
 * appointment's date, the facility's total, and the ROI estimate.
 */
class PilotReportTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var string Y-m-d, the consultant's day 1 */
    private $start;
    /** @var Facility */
    private $facility;

    protected function _before()
    {
        $this->createParticipants();
        PilotReport::$today = date('Y-m-d');
        $this->start = date('Y-m-d', strtotime('-75 days')); // today is day 76: month 3, under way
        Consultant::updateAll(['first_booking_at' => strtotime($this->start . ' 09:00')], ['id' => $this->consultant->id]);
        $this->consultant = Consultant::findOne($this->consultant->id);
        $this->facility = Facility::findOne(ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id);
    }

    protected function _after()
    {
        PilotReport::$today = null;
    }

    /** An appointment on pilot day $day, written straight to the table (no notices). */
    private function visit($day, $status, array $extra = [], $consultantUserId = null)
    {
        $date = date('Y-m-d', strtotime($this->start . ' +' . ($day - 1) . ' days'));
        Yii::$app->db->createCommand()->insert(Appointments::tableName(), $extra + [
            'date' => $date, 'time' => '10:00:00', 'patient_id' => $this->client->id,
            'consultant_id' => $consultantUserId ?? $this->consultantUser->id, 'facility_id' => $this->facility->id,
            'status' => $status, 'booking_channel' => Appointments::CHANNEL_STAFF, 'fee' => 2000, 'created_at' => time(),
        ])->execute();

        return (int) Yii::$app->db->getLastInsertID();
    }

    private function fee($appointmentId, $day, $state, $payer = CommitmentFee::PAYER_PATIENT)
    {
        (new CommitmentFee(['appointment_id' => $appointmentId, 'patient_id' => $this->client->id, 'consultant_id' => $this->consultantUser->id,
            'facility_id' => $this->facility->id, 'fee_date' => date('Y-m-d', strtotime($this->start . ' +' . ($day - 1) . ' days')),
            'amount' => 250, 'payer' => $payer, 'state' => $state]))->save(false);
    }

    private function baselineAndMonthTwo()
    {
        // Month 1: 4 attended, 2 no-shows (33.3%), 1 late cancellation, 1 walk-in
        foreach ([2, 3, 4] as $d) {
            $this->visit($d, Appointments::STATUS_COMPLETED);
        }
        $this->visit(5, Appointments::STATUS_COMPLETED, ['booking_channel' => Appointments::CHANNEL_WALK_IN]);
        $this->visit(6, Appointments::STATUS_NO_SHOW);
        $this->visit(7, Appointments::STATUS_NO_SHOW);
        $lateAt = strtotime($this->start . ' +7 days 10:00') - 2 * 3600;
        $this->visit(8, Appointments::STATUS_CANCELLED, ['cancelled_at_patient_request' => 1, 'cancelled_at' => $lateAt]);

        // Month 2: 9 attended, 1 no-show (10%); fees: 9 paid, the no-show's forfeited
        for ($d = 31; $d < 40; $d++) {
            $this->fee($this->visit($d, Appointments::STATUS_COMPLETED), $d, CommitmentFee::STATE_PAID);
        }
        $this->fee($this->visit(40, Appointments::STATUS_NO_SHOW), 40, CommitmentFee::STATE_FORFEITED);
    }

    public function testMonthsAreCountedFromTheConsultantsOwnFirstBooking()
    {
        $months = PilotReport::months($this->consultant);
        $this->assertSame($this->start, $months[1]['from']);
        $this->assertSame(date('Y-m-d', strtotime($this->start . ' +29 days')), $months[1]['to']);
        $this->assertSame('done', $months[1]['status']);
        $this->assertSame('done', $months[2]['status']);
        $this->assertSame('current', $months[3]['status']);
        $this->assertSame(76, $months[3]['day']);
    }

    public function testTheFiguresForEachMonth()
    {
        $this->baselineAndMonthTwo();
        $row = PilotReport::forConsultant($this->consultant, $this->facility->id);
        $m1 = $row['months'][1];
        $m2 = $row['months'][2];

        $this->assertEquals(7, $m1['bookings']);
        $this->assertEquals(4, $m1['attended']);
        $this->assertEquals(2, $m1['noShows']);
        $this->assertEqualsWithDelta(2 / 6, $m1['noShowRate'], 0.0001);
        $this->assertEquals(1, $m1['lateCancels']);
        $this->assertEquals(1, $m1['walkIns']);
        $this->assertEquals(6, $m1['ahead']);
        $this->assertEquals(0, $m1['feesCollected'], 'no fees in month 1');

        $this->assertEqualsWithDelta(0.1, $m2['noShowRate'], 0.0001);
        $this->assertEquals(2500, $m2['feesCollected'], 'paid and forfeited fees were both collected');
        $this->assertEquals(250, $m2['feesForfeited']);
        $this->assertEquals(0, $row['months'][3]['bookings']);
    }

    public function testTheRoiEstimate()
    {
        $this->baselineAndMonthTwo();
        $roi = PilotReport::forConsultant($this->consultant)['roi'];

        // (33.3% - 10%) x 10 visits with an outcome = 2.33 visits kept, x Ksh 2,000 average fee
        $this->assertEqualsWithDelta(-23.33, $roi['points'], 0.01);
        $this->assertEqualsWithDelta(2.333, $roi['visitsKept'], 0.001);
        $this->assertEqualsWithDelta(2000, $roi['averageFee'], 0.001);
        $this->assertEqualsWithDelta(4666.67, $roi['revenueKept'], 0.01);
        $this->assertEqualsWithDelta(4666.67 + 250, $roi['total'], 0.01);
    }

    public function testNoRoiWithoutABaseline()
    {
        $this->visit(35, Appointments::STATUS_COMPLETED);
        $this->assertNull(PilotReport::forConsultant($this->consultant)['roi']);
    }

    public function testTheFacilityAddsUpItsConsultantsEachFromTheirOwnStart()
    {
        $this->baselineAndMonthTwo();
        // A second consultant there, who started 10 days later: their day 2 is our day 12
        $other = $this->createUser('other.doctor', 'consultant');
        $second = new Consultant(['user_id' => $other->id, 'names' => 'Dr. Second', 'first_booking_at' => strtotime($this->start . ' +10 days 09:00')]);
        $second->save(false);
        if (!ConsultantFacility::find()->where(['consultant_id' => $second->id, 'facility_id' => $this->facility->id])->exists()) {
            (new ConsultantFacility(['consultant_id' => $second->id, 'facility_id' => $this->facility->id, 'session_minutes' => 30, 'booking_open' => 1]))->save(false);
        }
        $this->visit(12, Appointments::STATUS_NO_SHOW, [], $other->id);
        $this->visit(13, Appointments::STATUS_COMPLETED, [], $other->id);

        $report = PilotReport::forFacility($this->facility);
        $this->assertCount(2, $report['consultants']);
        $this->assertEquals(9, $report['total'][1]['bookings'], '7 + 2');
        $this->assertEqualsWithDelta(3 / 8, $report['total'][1]['noShowRate'], 0.0001);
        $this->assertSame('current', $report['total'][3]['status']);
        $this->assertNull($report['total'][3]['day'], 'no single day for the facility');

        // A consultant sees only their own
        $mine = PilotReport::forFacility($this->facility, [$this->consultant->id]);
        $this->assertCount(1, $mine['consultants']);

        $rows = PilotReport::csvRows($report);
        $this->assertSame('Facility', $rows[0][0]);
        $this->assertCount(1 + 2 * 3 + 3, $rows, 'header, each consultant\'s 3 months, the 3 totals');
    }
}
