<?php

namespace frontend\tests\unit;

use common\components\MpesaException;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\PatientCredit;
use frontend\services\BookingService;
use frontend\services\CommitmentFees;
use frontend\services\FeeLifecycle;
use frontend\services\FeeRules;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * F23: what becomes of a paid or absorbed commitment fee: credited when the clinic cancels or the
 * patient cancels early, forfeited when late or a no-show, moved with the booking; credits paying
 * the next fee, expiring, and refunds asked for and approved.
 */
class FeeLifecycleTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var callable */
    private $originalTransport;

    protected function _before()
    {
        $this->createParticipants();
        Yii::$app->params['commitmentFee']['enabled'] = true;
        Yii::$app->params['payouts.b2cEnabled'] = false;
        Consultant::updateAll(['first_booking_at' => strtotime('-39 days 10:00')], ['id' => $this->consultant->id]);
        FeeLifecycle::$now = null;
        $this->originalTransport = Yii::$app->mpesa->transport;
        Yii::$app->mpesa->transport = function () {
            throw new MpesaException('Tests do not call Safaricom.');
        };
    }

    protected function _after()
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        Yii::$app->params['payouts.b2cEnabled'] = false;
        Yii::$app->mpesa->transport = $this->originalTransport;
        FeeLifecycle::$now = null;
        Yii::$app->user->logout();
    }

    /** A paid booking at $time on $date (a laterMonday by default: more than 24 hours away). */
    private function paidBooking($time = '10:00', $date = null, $payer = CommitmentFee::PAYER_PATIENT)
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        $appointment = $this->newBooking($time, null, $date ?? $this->laterMonday());
        $appointment->saveBooking();
        Yii::$app->params['commitmentFee']['enabled'] = true;
        (new CommitmentFee([
            'appointment_id' => $appointment->id, 'patient_id' => $this->client->id, 'consultant_id' => $this->consultantUser->id,
            'facility_id' => $appointment->facility_id, 'fee_date' => $appointment->date, 'amount' => 250, 'payer' => $payer,
            'state' => $payer === CommitmentFee::PAYER_PATIENT ? CommitmentFee::STATE_PAID : CommitmentFee::STATE_ABSORBED,
        ]))->save(false);

        return Appointments::findOne($appointment->id);
    }

    private function fee(Appointments $appointment)
    {
        return CommitmentFee::forAppointment($appointment->id);
    }

    private function queuedEmails($contains)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])->andWhere(['like', 'job', $contains])->count();
    }

    public function testTheClinicCancellingTurnsThePaidFeeIntoARefundableCredit()
    {
        $appointment = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $this->assertTrue($appointment->cancel(null, Appointments::REASON_CLINIC));

        $this->assertSame(CommitmentFee::STATE_CREDITED, $this->fee($appointment)->state);
        $credit = PatientCredit::findOne(['patient_id' => $this->client->id]);
        $this->assertSame(PatientCredit::REASON_CLINIC_CANCELLED, $credit->reason);
        $this->assertSame(1, (int) $credit->refundable);
        $this->assertTrue($credit->isUsable());
        $this->assertEqualsWithDelta(time() + 90 * 86400, (int) $credit->expires_at, 5);
        $this->assertSame(1, $this->queuedEmails('kept as a credit'));
    }

    public function testAFacilityClosureCountsAsTheClinic()
    {
        $appointment = $this->paidBooking('10:00', $this->soonOpenDay());
        Yii::$app->user->login($this->consultantUser);
        $appointment->cancel(null, Appointments::REASON_FACILITY_CLOSED);

        $this->assertSame(CommitmentFee::STATE_CREDITED, $this->fee($appointment)->state, 'even within 24 hours');
    }

    public function testThePatientCancellingEarlyGetsACreditTheyCannotCashOut()
    {
        $appointment = $this->paidBooking();
        Yii::$app->user->login($this->client);
        $appointment->cancel('Travelling');

        $credit = PatientCredit::findOne(['patient_id' => $this->client->id]);
        $this->assertSame(PatientCredit::REASON_PATIENT_CANCELLED, $credit->reason);
        $this->assertSame(0, (int) $credit->refundable);
        $this->assertFalse($credit->canRequestRefund());
    }

    public function testThePatientCancellingLateForfeitsTheFee()
    {
        $appointment = $this->paidBooking('10:00', $this->soonOpenDay());
        // Cancelled 23 hours before
        Appointments::updateAll(['date' => date('Y-m-d', time() + 23 * 3600), 'time' => date('H:i:s', time() + 23 * 3600)], ['id' => $appointment->id]);
        $appointment->refresh();
        Yii::$app->user->login($this->client);
        $appointment->cancel();

        $this->assertSame(CommitmentFee::STATE_FORFEITED, $this->fee($appointment)->state);
        $this->assertNull(PatientCredit::findOne(['patient_id' => $this->client->id]));
    }

    public function testStaffCancellingAtThePatientsRequestFollowsThePatientsRules()
    {
        $appointment = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $appointment->cancel(null, Appointments::REASON_PATIENT_REQUEST);

        $this->assertSame(0, (int) PatientCredit::findOne(['patient_id' => $this->client->id])->refundable);
    }

    public function testAnAbsorbedFeeIsCancelledNotCreditedWhenTheClinicCancels()
    {
        $appointment = $this->paidBooking('10:00', null, CommitmentFee::PAYER_CONSULTANT);
        Yii::$app->user->login($this->consultantUser);
        $appointment->cancel(null, Appointments::REASON_CLINIC);

        $this->assertSame(CommitmentFee::STATE_CANCELLED, $this->fee($appointment)->state);
        $this->assertNull(PatientCredit::findOne(['patient_id' => $this->client->id]));
    }

    public function testANoShowForfeitsAndACorrectionRestores()
    {
        $appointment = $this->paidBooking('10:00', $this->soonOpenDay());
        Appointments::updateAll(['date' => date('Y-m-d', strtotime('-1 day'))], ['id' => $appointment->id]);
        $appointment->refresh();

        $this->assertTrue($appointment->recordOutcome(Appointments::STATUS_NO_SHOW));
        $this->assertSame(CommitmentFee::STATE_FORFEITED, $this->fee($appointment)->state);

        $this->assertTrue($appointment->recordOutcome(Appointments::STATUS_COMPLETED));
        $this->assertSame(CommitmentFee::STATE_PAID, $this->fee($appointment)->state);
    }

    public function testTheFeeMovesWithTheBooking()
    {
        $appointment = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $newDate = date('Y-m-d', strtotime($appointment->date . ' +7 days'));
        $this->assertTrue(Yii::createObject(BookingService::class)->reschedule($appointment, $newDate, '11:00:00'), json_encode($appointment->errors));

        $fee = $this->fee($appointment);
        $this->assertSame(CommitmentFee::STATE_PAID, $fee->state);
        $this->assertSame($newDate, $fee->fee_date);
    }

    public function testAPatientCannotMoveAPaidBookingInItsLast24Hours()
    {
        $appointment = $this->paidBooking('10:00', $this->soonOpenDay());
        Appointments::updateAll(['date' => date('Y-m-d', time() + 20 * 3600), 'time' => date('H:i:s', time() + 20 * 3600)], ['id' => $appointment->id]);
        $appointment->refresh();

        Yii::$app->user->login($this->client);
        $this->assertFalse(Yii::createObject(BookingService::class)->reschedule($appointment, $this->laterMonday(), '11:00:00'));
        $this->assertStringContainsString('cannot be moved', $appointment->getFirstError('time'));
    }

    public function testACreditPaysTheNextBookingWithoutAPrompt()
    {
        $cancelled = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $cancelled->cancel(null, Appointments::REASON_CLINIC);

        // The patient books again, with the same consultant here (any would do)
        Yii::$app->user->login($this->client);
        $next = $this->newBooking('14:00');
        FeeRules::load($next, ['payment_mode' => 'cash', 'fee_terms' => '1']);
        $this->assertTrue(Yii::createObject(BookingService::class)->book($next), json_encode($next->errors));

        $next->refresh();
        $this->assertSame(Appointments::STATUS_SCHEDULED, $next->status, 'confirmed at once');
        $fee = $this->fee($next);
        $this->assertSame(CommitmentFee::STATE_PAID, $fee->state);
        $credit = PatientCredit::findOne(['patient_id' => $this->client->id]);
        $this->assertSame(PatientCredit::STATUS_USED, $credit->status);
        $this->assertSame($fee->id, (int) $credit->used_fee_id);
    }

    public function testCreditsExpireAfter90Days()
    {
        $cancelled = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $cancelled->cancel(null, Appointments::REASON_CLINIC);

        FeeLifecycle::$now = time() + 91 * 86400;
        $this->assertNull(FeeLifecycle::usableCredit($this->client->id));
        $this->assertSame(1, FeeLifecycle::expireCredits());
        $this->assertSame(PatientCredit::STATUS_EXPIRED, PatientCredit::findOne(['patient_id' => $this->client->id])->status);
    }

    public function testARefundIsAskedApprovedAndRecordedByHand()
    {
        $cancelled = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $cancelled->cancel(null, Appointments::REASON_CLINIC);
        $credit = PatientCredit::findOne(['patient_id' => $this->client->id]);

        $this->assertTrue(FeeLifecycle::requestRefund($credit, '0722 000 111'));
        $this->assertSame(PatientCredit::STATUS_REFUND_REQUESTED, $credit->status);
        $this->assertSame('254722000111', $credit->refund_phone);
        $this->assertNull(FeeLifecycle::usableCredit($this->client->id), 'not usable while a refund is asked');

        $this->assertTrue(FeeLifecycle::approveRefund($credit, $this->admin->id), 'B2C off: approved to pay by hand');
        $this->assertFalse(FeeLifecycle::markRefunded($credit, 'bad ref!', $this->admin->id));
        $this->assertTrue(FeeLifecycle::markRefunded($credit, 'ukg8420xho', $this->admin->id));

        $credit->refresh();
        $this->assertSame(PatientCredit::STATUS_REFUNDED, $credit->status);
        $this->assertSame('UKG8420XHO', $credit->refund_reference);
        $this->assertSame(CommitmentFee::STATE_REFUNDED, $this->fee($cancelled)->state);
    }

    public function testADeclinedRefundLeavesTheCreditUsable()
    {
        $cancelled = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $cancelled->cancel(null, Appointments::REASON_CLINIC);
        $credit = PatientCredit::findOne(['patient_id' => $this->client->id]);
        FeeLifecycle::requestRefund($credit);

        $this->assertTrue(FeeLifecycle::rejectRefund($credit, $this->admin->id, 'Duplicate request'));
        $this->assertSame(PatientCredit::STATUS_REFUND_REJECTED, $credit->status);
        $this->assertSame($credit->id, FeeLifecycle::usableCredit($this->client->id)->id);
    }

    public function testWithB2COnAnApprovedRefundIsSent()
    {
        Yii::$app->params['payouts.b2cEnabled'] = true;
        $cancelled = $this->paidBooking();
        Yii::$app->user->login($this->consultantUser);
        $cancelled->cancel(null, Appointments::REASON_CLINIC);
        $credit = PatientCredit::findOne(['patient_id' => $this->client->id]);
        FeeLifecycle::requestRefund($credit);

        // Safaricom is not called in tests: the send fails and the refund stays requested
        $this->assertFalse(FeeLifecycle::approveRefund($credit, $this->admin->id));
        $credit->refresh();
        $this->assertSame(PatientCredit::STATUS_REFUND_REQUESTED, $credit->status);
        $this->assertStringContainsString('B2C', $credit->note);
    }
}
