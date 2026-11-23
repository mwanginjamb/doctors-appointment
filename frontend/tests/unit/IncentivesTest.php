<?php

namespace frontend\tests\unit;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\FrontdeskIncentive;
use frontend\models\FrontDeskAssignment;
use frontend\models\IncentivePayout;
use frontend\services\BookingService;
use frontend\services\FeeRules;
use frontend\services\Incentives;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F24: the Ksh 50 front-desk incentive: which bookings earn it, when it is earned or cancelled,
 * the cancel-and-rebook flag, and the weekly payouts.
 */
class IncentivesTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;

    protected function _before()
    {
        $this->createParticipants();
        FrontDesk::reset();
        Yii::$app->params['commitmentFee']['enabled'] = true;
        Yii::$app->params['payouts.b2cEnabled'] = false;
        // Absorbed fees for insured patients: no M-Pesa prompt to fake
        Consultant::updateAll(['first_booking_at' => strtotime('-39 days 10:00'), 'insured_pay' => 0], ['id' => $this->consultant->id]);
        $this->frontDesk = $this->createUser('front.desk', User::ACCOUNT_FRONT_DESK);
        $this->frontDesk->updateAttributes(['facility_id' => $this->consultant->engagements[0]->facility_id, 'phone_number' => '0711222333']);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
        Incentives::$now = null;
    }

    protected function _after()
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        Incentives::$now = null;
        FrontDesk::reset();
        Yii::$app->user->logout();
    }

    /** Books the client as $user (the front desk by default), insured: the consultant absorbs the fee. */
    private function bookAs($user = null, $time = '10:00', $date = null, $patient = null)
    {
        Yii::$app->user->login($user ?? $this->frontDesk);
        $appointment = $this->newBooking($time, $patient, $date);
        // F18: an insured booking names the insurer and member number
        $insurer = \frontend\models\Provider::findOne(['provider' => 'Jubilee']) ?? new \frontend\models\Provider(['provider' => 'Jubilee']);
        $insurer->save(false);
        FeeRules::load($appointment, ['payment_mode' => 'insurance', 'insurer_id' => $insurer->id, 'member_number' => 'JUB-1']);
        $this->assertTrue(Yii::createObject(BookingService::class)->book($appointment), json_encode($appointment->errors));

        return Appointments::findOne($appointment->id);
    }

    private function incentive(Appointments $appointment)
    {
        return FrontdeskIncentive::forAppointment($appointment->id);
    }

    /** Moves the appointment into the past, so its outcome can be recorded. */
    private function past(Appointments $appointment)
    {
        Appointments::updateAll(['date' => date('Y-m-d', strtotime('-1 day'))], ['id' => $appointment->id]);
        $appointment->refresh();

        return $appointment;
    }

    public function testAFrontDeskBookingAheadEarnsAPendingIncentive()
    {
        $appointment = $this->bookAs();

        $this->assertSame(CommitmentFee::STATE_ABSORBED, CommitmentFee::forAppointment($appointment->id)->state);
        $incentive = $this->incentive($appointment);
        $this->assertSame(FrontdeskIncentive::STATE_PENDING, $incentive->state);
        $this->assertSame(50, (int) $incentive->amount);
        $this->assertSame($this->frontDesk->id, (int) $incentive->front_desk_id);
    }

    public function testOnlyFrontDeskBookingsAheadEarn()
    {
        $this->assertNull($this->incentive($this->bookAs($this->consultantUser, '10:00')), 'the consultant booking');
        $this->assertNull($this->incentive($this->bookAs($this->client, '11:00')), 'the patient online');

        Yii::$app->params['commitmentFee']['enabled'] = false;
        $this->assertNull($this->incentive($this->bookAs($this->frontDesk, '12:00', null, $this->otherClient)), 'no fee, no incentive');
    }

    public function testAttendingEarnsItANoShowCancelsItAndACorrectionRestoresIt()
    {
        $appointment = $this->past($this->bookAs());
        Yii::$app->user->login($this->frontDesk);

        $appointment->recordOutcome(Appointments::STATUS_COMPLETED);
        $this->assertSame(FrontdeskIncentive::STATE_EARNED, $this->incentive($appointment)->state);

        $appointment->recordOutcome(Appointments::STATUS_NO_SHOW);
        $this->assertSame(FrontdeskIncentive::STATE_CANCELLED, $this->incentive($appointment)->state);

        $appointment->recordOutcome(Appointments::STATUS_COMPLETED);
        $this->assertSame(FrontdeskIncentive::STATE_EARNED, $this->incentive($appointment)->state);
    }

    public function testACancellationCancelsIt()
    {
        $appointment = $this->bookAs();
        $appointment->cancel(null, Appointments::REASON_PATIENT_REQUEST);

        $this->assertSame(FrontdeskIncentive::STATE_CANCELLED, $this->incentive($appointment)->state);
    }

    public function testCancelAndRebookOfTheSamePatientWithin24HoursIsFlagged()
    {
        $first = $this->bookAs();
        $first->cancel(null, Appointments::REASON_PATIENT_REQUEST);

        $rebooked = $this->bookAs($this->frontDesk, '11:00');
        $incentive = $this->incentive($rebooked);
        $this->assertSame(FrontdeskIncentive::STATE_CANCELLED, $incentive->state);
        $this->assertSame(1, (int) $incentive->flagged);
        $this->assertStringContainsString('#' . $first->id, $incentive->note);

        // Another patient is not affected
        $this->assertSame(FrontdeskIncentive::STATE_PENDING, $this->incentive($this->bookAs($this->frontDesk, '12:00', null, $this->otherClient))->state);
    }

    public function testEarnedIncentivesArePaidWeekly()
    {
        $one = $this->past($this->bookAs());
        $two = $this->past($this->bookAs($this->frontDesk, '11:00', null, $this->otherClient));
        Yii::$app->user->login($this->frontDesk);
        $one->recordOutcome(Appointments::STATUS_COMPLETED);
        $two->recordOutcome(Appointments::STATUS_COMPLETED);

        // The following Monday
        $monday = strtotime('next monday 06:00');
        Incentives::$now = $monday;
        $payouts = Incentives::buildWeeklyPayouts($monday);
        $this->assertCount(1, $payouts);
        $payout = $payouts[0];
        $this->assertSame(100, (int) $payout->amount);
        $this->assertSame(2, (int) $payout->incentives);
        $this->assertSame(IncentivePayout::STATUS_READY, $payout->status);
        $this->assertSame('254711222333', $payout->phone);
        $this->assertSame(date('Y-m-d', strtotime('monday last week', $monday)), $payout->period_start);

        $this->assertSame([], Incentives::buildWeeklyPayouts($monday), 'safe to run again');

        $this->assertFalse(Incentives::send($payout, $this->admin->id), 'B2C is off');
        $this->assertTrue(Incentives::markPaid($payout, 'ukg8420xho', $this->admin->id));
        $this->assertSame(IncentivePayout::STATUS_PAID, $payout->status);
        $this->assertSame(FrontdeskIncentive::STATE_PAID, $this->incentive($one)->state);
        $this->assertSame(FrontdeskIncentive::STATE_PAID, $this->incentive($two)->state);
    }

    public function testAPayoutIsHeldWithoutAKenyanPhone()
    {
        $appointment = $this->past($this->bookAs());
        Yii::$app->user->login($this->frontDesk);
        $appointment->recordOutcome(Appointments::STATUS_COMPLETED);
        $this->frontDesk->updateAttributes(['phone_number' => null]);

        $monday = strtotime('next monday 06:00');
        $payout = Incentives::buildWeeklyPayouts($monday)[0];
        $this->assertSame(IncentivePayout::STATUS_HELD, $payout->status);

        $this->frontDesk->updateAttributes(['phone_number' => '0711222333']);
        $this->assertTrue(Incentives::refreshPhone($payout));
        $this->assertSame(IncentivePayout::STATUS_READY, $payout->status);
    }

    public function testAPaidIncentiveIsNotCancelledAfterwards()
    {
        $appointment = $this->past($this->bookAs());
        Yii::$app->user->login($this->frontDesk);
        $appointment->recordOutcome(Appointments::STATUS_COMPLETED);
        $payout = Incentives::buildWeeklyPayouts(strtotime('next monday 06:00'))[0];
        Incentives::markPaid($payout, 'UKG8420XHO', $this->admin->id);

        $appointment->recordOutcome(Appointments::STATUS_NO_SHOW);
        $this->assertSame(FrontdeskIncentive::STATE_PAID, $this->incentive($appointment)->state);
    }
}
