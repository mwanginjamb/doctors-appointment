<?php

namespace frontend\tests\unit;

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\FacilityAgreement;
use frontend\services\FeeRules;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F22: which bookings carry the Ksh 250 commitment fee, and who pays it.
 */
class FeeRulesTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var int */
    private $facilityId;

    protected function _before()
    {
        $this->createParticipants();
        $this->facilityId = (int) $this->consultant->engagements[0]->facility_id;
        Yii::$app->params['commitmentFee']['enabled'] = true;
        $this->onDay(40);
    }

    protected function _after()
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
    }

    private function onDay($day)
    {
        Consultant::updateAll(['first_booking_at' => strtotime('-' . ($day - 1) . ' days 10:00')], ['id' => $this->consultant->id]);
    }

    private function booking(array $attributes = [])
    {
        $appointment = $this->newBooking('10:00');
        $appointment->facility_id = $this->facilityId;
        $appointment->booking_channel = Appointments::CHANNEL_ONLINE;
        $appointment->payment_mode = FeeRules::PAYMENT_CASH;
        $appointment->setAttributes($attributes, false);

        return $appointment;
    }

    /** A booking that is only there to have a fee on the same day: booked with fees off */
    private function bookFixture($time)
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        $appointment = $this->book($time);
        Yii::$app->params['commitmentFee']['enabled'] = true;

        return $appointment;
    }

    private function decision(Appointments $appointment)
    {
        return FeeRules::decide($appointment)[0];
    }

    public function testAnOnlineCashBookingFromDay31Pays()
    {
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking()));
        $this->onDay(31);
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking()));
    }

    public function testNothingWhenFeesAreSwitchedOff()
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        [$decision, $reason] = FeeRules::decide($this->booking());
        $this->assertSame(FeeRules::NONE, $decision);
        $this->assertStringContainsString('switched off', $reason);
    }

    public function testNothingInTheFirst30DaysOrBeforeAnyBooking()
    {
        $this->onDay(30);
        $this->assertSame(FeeRules::NONE, $this->decision($this->booking()));

        Consultant::updateAll(['first_booking_at' => null], ['id' => $this->consultant->id]);
        $this->assertSame(FeeRules::NONE, $this->decision($this->booking()));
    }

    public function testNothingOnPlanB()
    {
        Consultant::updateAll(['plan' => FeeRules::PLAN_B], ['id' => $this->consultant->id]);
        $this->assertSame(FeeRules::NONE, $this->decision($this->booking()));
    }

    public function testSameDayWalkInsAreExemptUnlessTheFacilityChargesThem()
    {
        $walkIn = $this->booking(['booking_channel' => Appointments::CHANNEL_WALK_IN]);
        $this->assertSame(FeeRules::NONE, $this->decision($walkIn), 'exempt without an agreement');

        (new FacilityAgreement(['facility_id' => $this->facilityId, 'version' => '1.2', 'signed_on' => '2026-08-01',
            'facility_signatory' => 'x', 'platform_signatory' => 'y', 'walk_in_exempt' => 0]))->save(false);
        $this->assertSame(FeeRules::CHARGE, $this->decision($walkIn));

        // Bookings by link or by staff for a later day always pay
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking(['booking_channel' => Appointments::CHANNEL_LINK])));
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking(['booking_channel' => Appointments::CHANNEL_STAFF])));
    }

    public function testOneFeeADayAcrossConsultants()
    {
        $earlier = $this->bookFixture('09:00');
        $fee = new CommitmentFee([
            'appointment_id' => $earlier->id, 'patient_id' => $this->client->id, 'consultant_id' => 999,
            'fee_date' => $this->date, 'amount' => 250, 'payer' => CommitmentFee::PAYER_PATIENT, 'state' => CommitmentFee::STATE_PAID,
        ]);
        $fee->save(false);
        $this->assertSame(FeeRules::NONE, $this->decision($this->booking()));

        // Another day pays again; a failed payment does not count
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking(['date' => date('Y-m-d', strtotime($this->date . ' +1 day'))])));
        $fee->updateAttributes(['state' => CommitmentFee::STATE_FAILED]);
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking()));
    }

    public function testAnAbsorbedFeeDoesNotExemptTheNextConsultant()
    {
        $earlier = $this->bookFixture('09:00');
        (new CommitmentFee([
            'appointment_id' => $earlier->id, 'patient_id' => $this->client->id, 'consultant_id' => 999,
            'fee_date' => $this->date, 'amount' => 250, 'payer' => CommitmentFee::PAYER_CONSULTANT, 'state' => CommitmentFee::STATE_ABSORBED,
        ]))->save(false);

        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking()), 'the patient has not paid that day');
    }

    public function testInsuredPatientsPayUnlessTheConsultantAbsorbs()
    {
        $insured = $this->booking(['payment_mode' => FeeRules::PAYMENT_INSURANCE]);
        $this->assertSame(FeeRules::CHARGE, $this->decision($insured), 'insured patients pay by default');

        Consultant::updateAll(['insured_pay' => 0], ['id' => $this->consultant->id]);
        $this->assertSame(FeeRules::ABSORB, $this->decision($insured));
        $this->assertSame(FeeRules::CHARGE, $this->decision($this->booking()), 'cash patients still pay');
    }

    public function testConsultantsStartOnPlanAWithInsuredPatientsPaying()
    {
        $consultant = Consultant::findOne($this->consultant->id);
        $this->assertSame(FeeRules::PLAN_A, $consultant->plan);
        $this->assertSame(1, (int) $consultant->insured_pay);
    }
}
