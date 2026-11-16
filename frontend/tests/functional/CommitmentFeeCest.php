<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\services\CommitmentFees;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use Yii;

/**
 * F22 part 2 in the browser: the fee questions on the calendar, booking to the pay page, and the
 * pay page itself. M-Pesa is not called in tests: the prompt fails to send, which the page shows.
 */
class CommitmentFeeCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        Yii::$app->params['commitmentFee']['enabled'] = true;
        Consultant::updateAll(['first_booking_at' => strtotime('-39 days 10:00')], ['id' => $this->consultant->id]);
    }

    public function _after(FunctionalTester $I)
    {
        Yii::$app->params['commitmentFee']['enabled'] = false;
    }

    private function bookFromTheCalendar(FunctionalTester $I, array $fee)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);

        $response = $I->sendJsonPost(['/api/visit'], $fee + [
            'date' => $this->date, 'time' => '10:00:00', 'brief' => 'Headache', 'consultant' => $this->consultantUser->id,
        ]);
        // In tests the app is reused, and the JSON call leaves its response format behind
        Yii::$app->response->format = \yii\web\Response::FORMAT_HTML;

        return $response;
    }

    public function theCalendarAsksAboutTheFee(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->seeElement('#appointment-fee-fields');
        $I->see('Commitment fee: Ksh 250');
    }

    public function noFeeQuestionsInTheFirst30Days(FunctionalTester $I)
    {
        Consultant::updateAll(['first_booking_at' => strtotime('-5 days')], ['id' => $this->consultant->id]);
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->dontSeeElement('#appointment-fee-fields');
    }

    public function bookingWithoutTheTermsIsRefused(FunctionalTester $I)
    {
        $response = $this->bookFromTheCalendar($I, ['payment_mode' => 'cash', 'pay_phone' => '0712345678']);

        $I->assertSame('error', $response['status']);
        $I->assertStringContainsString('booking terms', $response['message']);
        $I->assertFalse(Appointments::find()->where(['date' => $this->date, 'patient_id' => $this->client->id])->exists());
    }

    public function bookingGoesToThePayPage(FunctionalTester $I)
    {
        $response = $this->bookFromTheCalendar($I, ['payment_mode' => 'cash', 'pay_phone' => '0712345678', 'fee_terms' => true]);

        $I->assertSame('success', $response['status'], json_encode($response));
        $appointment = Appointments::findOne(['date' => $this->date, 'patient_id' => $this->client->id]);
        $I->assertSame(Appointments::STATUS_AWAITING_PAYMENT, $appointment->status);
        $I->assertSame(CommitmentFees::payUrl($appointment), $response['payUrl']);

        $I->amOnPage($response['payUrl']);
        $I->see('Check your phone');
        $I->see('The M-Pesa prompt could not be sent'); // tests do not call Safaricom
        $I->seeElement('#pay-resend');

        $I->amOnPage($response['payUrl'] . '/status');
        $state = json_decode($I->grabPageSource(), true);
        $I->assertSame(['awaiting_payment', 'pending', true], [$state['status'], $state['fee'], $state['held']]);
        Yii::$app->response->format = \yii\web\Response::FORMAT_HTML;
    }

    public function thePayPageCancelsAHeldBooking(FunctionalTester $I)
    {
        $response = $this->bookFromTheCalendar($I, ['payment_mode' => 'cash', 'pay_phone' => '0712345678', 'fee_terms' => true]);
        $I->amOnPage($response['payUrl']);
        $I->submitForm('form[action$="/cancel"]', []);

        $I->see('The booking is cancelled');
        $appointment = Appointments::findOne(['date' => $this->date, 'patient_id' => $this->client->id]);
        $I->assertSame(Appointments::STATUS_CANCELLED, $appointment->status);
        $I->assertSame(CommitmentFee::STATE_CANCELLED, CommitmentFee::forAppointment($appointment->id)->state);
    }

    public function aWrongSignatureIsRefused(FunctionalTester $I)
    {
        $response = $this->bookFromTheCalendar($I, ['payment_mode' => 'cash', 'pay_phone' => '0712345678', 'fee_terms' => true]);
        $appointment = Appointments::findOne(['date' => $this->date, 'patient_id' => $this->client->id]);

        $I->amOnPage('/pay/' . $appointment->id . '/' . str_repeat('0', 32));
        $I->seeResponseCodeIs(404);
    }

    public function aBookingByLinkPaysOnThePayPageAndIsNotConfirmedYet(FunctionalTester $I)
    {
        Yii::$app->user->setIdentity($this->consultantUser);
        [$link, $token] = \frontend\models\BookingLink::issue([
            'consultant_id' => $this->consultant->id, 'recipient_name' => 'Client', 'recipient_phone' => '0712345678',
            'from_date' => $this->date, 'to_date' => $this->date, 'day_start' => '14:00:00', 'day_end' => '15:00:00',
        ]);
        Yii::$app->user->setIdentity(null);
        $whatsappBefore = (int) (new \yii\db\Query())->from('{{%queue}}')->where(['like', 'job', 'SendWhatsappTextJob'])->count();

        $I->amOnPage('/book/' . $token);
        $I->seeElement('#book-fee-fields');
        $I->submitForm('#remote-walk-in-form', [
            'RemoteWalkInForm[date]' => $this->date,
            'RemoteWalkInForm[time]' => '14:30',
            'RemoteWalkInForm[consent_data]' => '1',
            'RemoteWalkInForm[consent_reminders]' => '1',
            'RemoteWalkInForm[full_name]' => 'Client',
            'payment_mode' => 'cash',
            'fee_terms' => '1',
        ]);

        $I->see('Check your phone');
        $appointment = Appointments::findOne(['booking_link_id' => $link->id]);
        $I->assertSame(Appointments::STATUS_AWAITING_PAYMENT, $appointment->status);
        $I->assertSame('254712345678', CommitmentFee::forAppointment($appointment->id)->phone, "the link's number pays");
        $I->assertSame($whatsappBefore, (int) (new \yii\db\Query())->from('{{%queue}}')->where(['like', 'job', 'SendWhatsappTextJob'])->count(), 'confirmed only once paid');
    }

    public function staffSeeTheFeeOnTheAppointment(FunctionalTester $I)
    {
        $this->bookFromTheCalendar($I, ['payment_mode' => 'cash', 'pay_phone' => '0712345678', 'fee_terms' => true]);
        $appointment = Appointments::findOne(['date' => $this->date, 'patient_id' => $this->client->id]);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $appointment->id]);
        $I->see('Waiting for payment', '#commitment-fee');
        $I->see('Open the payment page', '#commitment-fee');
    }
}
