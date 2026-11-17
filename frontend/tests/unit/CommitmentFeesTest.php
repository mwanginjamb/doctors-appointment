<?php

namespace frontend\tests\unit;

use common\components\MpesaException;
use common\models\MpesaTransaction;
use frontend\models\AppointmentNotifications;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\services\BookingService;
use frontend\services\CommitmentFees;
use frontend\services\FeeRules;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;
use yii\helpers\Json;

/**
 * F22 part 2: paying the commitment fee at booking: the held slot, the M-Pesa prompt, the payment
 * confirming the booking, and releasing it when the payment does not arrive.
 */
class CommitmentFeesTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var array<string, array[]> path => queue of [status, body] */
    private $answers = [];
    /** @var array[] */
    private $requests = [];
    /** @var callable */
    private $originalTransport;

    protected function _before()
    {
        $this->createParticipants();
        Yii::$app->params['commitmentFee']['enabled'] = true;
        Consultant::updateAll(['first_booking_at' => strtotime('-39 days 10:00')], ['id' => $this->consultant->id]);
        Yii::$app->cache->flush();
        CommitmentFees::$now = null;

        $mpesa = Yii::$app->mpesa;
        $this->originalTransport = $mpesa->transport;
        $mpesa->transport = function ($method, $url, $headers, $body) {
            $path = parse_url($url, PHP_URL_PATH);
            $this->requests[] = ['path' => $path, 'body' => $body === null ? null : Json::decode($body)];
            if (empty($this->answers[$path])) {
                throw new MpesaException("No answer prepared for $path");
            }

            return array_shift($this->answers[$path]);
        };
        $this->answer('/oauth/v1/generate', ['access_token' => 'token-1', 'expires_in' => '3599']);
    }

    protected function _after()
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        Yii::$app->mpesa->transport = $this->originalTransport;
        CommitmentFees::$now = null;
        Yii::$app->user->logout();
    }

    private function answer($path, array $body, $status = 200)
    {
        $this->answers[$path][] = [$status, Json::encode($body)];
    }

    private function answerStkAccepted($checkout = 'ws_CO_1')
    {
        $this->answer('/mpesa/stkpush/v1/processrequest', [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => $checkout,
            'ResponseCode' => '0', 'ResponseDescription' => 'Accepted', 'CustomerMessage' => 'Accepted',
        ]);
    }

    private function answerQuery($code, $description = 'Done')
    {
        $this->answer('/mpesa/stkpushquery/v1/query', ['ResultCode' => (string) $code, 'ResultDesc' => $description]);
    }

    private function requestsTo($path)
    {
        return array_values(array_filter($this->requests, function ($r) use ($path) { return $r['path'] === $path; }));
    }

    /** Books 10:00 as the client, online, paying in cash from 0712 345 678 with the terms accepted. */
    private function bookPaying(array $fee = [], $time = '10:00', $patient = null)
    {
        Yii::$app->user->login($patient ?? $this->client);
        $appointment = $this->newBooking($time, $patient);
        FeeRules::load($appointment, $fee + ['payment_mode' => 'cash', 'pay_phone' => '0712 345 678', 'fee_terms' => '1']);
        $booked = Yii::createObject(BookingService::class)->book($appointment);

        return [$booked, $appointment];
    }

    private function queuedEmails($contains)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])->andWhere(['like', 'job', $contains])->count();
    }

    public function testAChargedBookingHoldsItsSlotAndPromptsThePhone()
    {
        $this->answerStkAccepted();
        [$booked, $appointment] = $this->bookPaying();

        $this->assertTrue($booked, json_encode($appointment->errors));
        $appointment->refresh();
        $this->assertSame(Appointments::STATUS_AWAITING_PAYMENT, $appointment->status);
        $this->assertEqualsWithDelta(time() + 600, (int) $appointment->hold_expires_at, 5);

        $fee = CommitmentFee::forAppointment($appointment->id);
        $this->assertSame(CommitmentFee::STATE_PENDING, $fee->state);
        $this->assertSame('254712345678', $fee->phone);
        $this->assertNotNull($fee->terms_accepted_at);
        $this->assertNotNull($fee->mpesa_transaction_id);

        $push = $this->requestsTo('/mpesa/stkpush/v1/processrequest')[0]['body'];
        $this->assertSame('254712345678', (string) $push['PhoneNumber']);
        $this->assertSame(250, (int) $push['Amount']);

        $this->assertSame(0, (int) AppointmentNotifications::find()->where(['appointment_id' => $appointment->id])->count(), 'no reminders before it is paid');

        // The held time is taken for everyone else
        $other = $this->newBooking('10:00', $this->otherClient);
        $this->assertNotNull(Yii::createObject(BookingService::class)->slotProblem($other));
    }

    public function testPaymentConfirmsTheBooking()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $fee = CommitmentFee::forAppointment($appointment->id);

        $this->answerQuery(0, 'The service request is processed successfully.');
        Yii::$app->mpesa->refreshStk(MpesaTransaction::findOne($fee->mpesa_transaction_id));

        $fee->refresh();
        $appointment->refresh();
        $this->assertSame(CommitmentFee::STATE_PAID, $fee->state);
        $this->assertNotNull($fee->paid_at);
        $this->assertSame(Appointments::STATUS_SCHEDULED, $appointment->status);
        $this->assertNull($appointment->hold_expires_at);
        $this->assertGreaterThan(0, (int) AppointmentNotifications::find()->where(['appointment_id' => $appointment->id])->count(), 'reminders once paid');
    }

    public function testACancelledPromptCanBeSentAgainWhileHeld()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $fee = CommitmentFee::forAppointment($appointment->id);

        $this->answerQuery(1032, 'Request cancelled by user');
        Yii::$app->mpesa->refreshStk(MpesaTransaction::findOne($fee->mpesa_transaction_id));
        $fee->refresh();
        $this->assertSame(CommitmentFee::STATE_PENDING, $fee->state);
        $this->assertSame('Request cancelled by user', $fee->note);

        $this->answerStkAccepted('ws_CO_2');
        $appointment->refresh();
        $this->assertTrue(CommitmentFees::resend($appointment));
        $this->assertCount(2, $this->requestsTo('/mpesa/stkpush/v1/processrequest'));
    }

    public function testThePromptIsNotResentWithinAMinute()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $appointment->refresh();

        $this->assertFalse(CommitmentFees::resend($appointment));
        $this->assertCount(1, $this->requestsTo('/mpesa/stkpush/v1/processrequest'));
    }

    public function testTheTermsMustBeAccepted()
    {
        [$booked, $appointment] = $this->bookPaying(['fee_terms' => '']);

        $this->assertFalse($booked);
        $this->assertArrayHasKey('feeTermsAccepted', $appointment->errors);
        $this->assertSame([], $this->requestsTo('/mpesa/stkpush/v1/processrequest'));
    }

    public function testAnInsuredPatientsAbsorbedFeeIsConfirmedAtOnce()
    {
        Consultant::updateAll(['insured_pay' => 0], ['id' => $this->consultant->id]);
        [$booked, $appointment] = $this->bookPaying(['payment_mode' => 'insurance', 'fee_terms' => '']);

        $this->assertTrue($booked, json_encode($appointment->errors));
        $appointment->refresh();
        $this->assertSame(Appointments::STATUS_SCHEDULED, $appointment->status);
        $fee = CommitmentFee::forAppointment($appointment->id);
        $this->assertSame(CommitmentFee::STATE_ABSORBED, $fee->state);
        $this->assertSame(CommitmentFee::PAYER_CONSULTANT, $fee->payer);
        $this->assertSame([], $this->requestsTo('/mpesa/stkpush/v1/processrequest'));
    }

    public function testNoFeeNoHoldWhenFeesAreOff()
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
        [$booked, $appointment] = $this->bookPaying(['fee_terms' => '']);

        $this->assertTrue($booked);
        $appointment->refresh();
        $this->assertSame(Appointments::STATUS_SCHEDULED, $appointment->status);
        $this->assertNull(CommitmentFee::forAppointment($appointment->id));
    }

    public function testAnExpiredHoldIsReleasedAndThePatientTold()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $fee = CommitmentFee::forAppointment($appointment->id);

        // Still unanswered when the hold runs out
        CommitmentFees::$now = time() + 601;
        $this->answer('/mpesa/stkpushquery/v1/query', ['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500);
        $this->assertSame(1, CommitmentFees::releaseExpired());

        $appointment->refresh();
        $fee->refresh();
        $this->assertSame(Appointments::STATUS_UNPAID, $appointment->status);
        $this->assertSame(CommitmentFee::STATE_FAILED, $fee->state);
        $this->assertSame(1, $this->queuedEmails('was not completed'));
        $this->assertSame(0, $this->queuedEmails('is cancelled'), 'not a cancellation');

        // The time is free again
        CommitmentFees::$now = null;
        $other = $this->newBooking('10:00', $this->otherClient);
        $this->assertNull(Yii::createObject(BookingService::class)->slotProblem($other));
    }

    public function testAPaymentFoundAtReleaseTimeConfirmsInstead()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();

        CommitmentFees::$now = time() + 601;
        $this->answerQuery(0, 'The service request is processed successfully.');
        $this->assertSame(0, CommitmentFees::releaseExpired());

        $appointment->refresh();
        $this->assertSame(Appointments::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame(CommitmentFee::STATE_PAID, CommitmentFee::forAppointment($appointment->id)->state);
    }

    public function testALatePaymentRebooksTheTimeIfStillFree()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $fee = CommitmentFee::forAppointment($appointment->id);
        CommitmentFees::$now = time() + 601;
        $this->answer('/mpesa/stkpushquery/v1/query', ['errorCode' => '500.001.1001', 'errorMessage' => 'Processing'], 500);
        CommitmentFees::releaseExpired();
        CommitmentFees::$now = null;

        CommitmentFees::markPaid($fee, MpesaTransaction::findOne($fee->mpesa_transaction_id));

        $appointment->refresh();
        $this->assertSame(Appointments::STATUS_SCHEDULED, $appointment->status);
    }

    public function testALatePaymentForATimeTakenSinceBecomesARefundableCredit()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $fee = CommitmentFee::forAppointment($appointment->id);
        CommitmentFees::$now = time() + 601;
        $this->answer('/mpesa/stkpushquery/v1/query', ['errorCode' => '500.001.1001', 'errorMessage' => 'Processing'], 500);
        CommitmentFees::releaseExpired();
        CommitmentFees::$now = null;
        Yii::$app->params['commitmentFee']['enabled'] = false;
        $this->book('10:00', $this->otherClient);

        CommitmentFees::markPaid($fee, MpesaTransaction::findOne($fee->mpesa_transaction_id));

        $appointment->refresh();
        $fee->refresh();
        $this->assertSame(Appointments::STATUS_UNPAID, $appointment->status);
        $this->assertSame(CommitmentFee::STATE_CREDITED, $fee->state);
        $credit = \frontend\models\PatientCredit::findOne(['source_fee_id' => $fee->id]);
        $this->assertSame(\frontend\models\PatientCredit::REASON_LATE_PAYMENT, $credit->reason);
        $this->assertSame(1, (int) $credit->refundable);
    }

    public function testAHeldBookingCanBeCancelledButNotMoved()
    {
        $this->answerStkAccepted();
        [, $appointment] = $this->bookPaying();
        $appointment->refresh();

        $this->assertFalse(Yii::createObject(BookingService::class)->reschedule($appointment, $this->date, '11:00:00'));
        $this->assertStringContainsString('waiting for its commitment fee', $appointment->getFirstError('time'));

        $appointment->refresh();
        $this->assertTrue($appointment->cancel());
        $this->assertSame(CommitmentFee::STATE_CANCELLED, CommitmentFee::forAppointment($appointment->id)->state);
        $this->assertSame(0, $this->queuedEmails('is cancelled'), 'nothing to tell: it was never confirmed');
    }
}
