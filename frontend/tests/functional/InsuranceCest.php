<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\Provider;
use frontend\services\FeeRules;
use frontend\services\Insurance;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use Yii;

/**
 * F18 on the pages: cash or insurance on every booking form, the insurer and pre-authorisation on
 * the appointment, and the board's pre-auth filter.
 */
class InsuranceCest
{
    use BookingDataTrait;

    /** @var Provider */
    private $jubilee;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        Yii::$app->params['commitmentFee']['enabled'] = false;
        $this->jubilee = new Provider(['provider' => 'Jubilee']);
        $this->jubilee->save(false);
        Consultant::updateAll(['covers_supported' => (string) $this->jubilee->id], ['id' => $this->consultant->id]);
    }

    private function insuredBooking()
    {
        Yii::$app->user->login($this->client);
        $appointment = $this->newBooking('10:00');
        FeeRules::load($appointment, ['payment_mode' => 'insurance', 'insurer_id' => $this->jubilee->id, 'member_number' => 'JUB-55']);
        $appointment->saveBooking();
        Yii::$app->user->logout();

        return Appointments::findOne($appointment->id);
    }

    public function theCalendarAsksCashOrInsuranceEvenWithFeesOff(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->seeElement('#appointment-pay-fields');
        $I->seeElement('#appointment-insurer');
        $I->see('Jubilee', '#appointment-insurer');
        $I->seeElement('#appointment-member');
        $I->dontSeeElement('#appointment-fee-fields');
    }

    public function staffRecordPreauthOnTheAppointment(FunctionalTester $I)
    {
        $appointment = $this->insuredBooking();
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('/appointments/view', ['id' => $appointment->id]);
        $I->see('Jubilee · member JUB-55', '#appointment-insurance');
        $I->see('Pre-auth pending', '#preauth-status');
        $I->submitForm('#preauth-form', ['preauth_status' => Insurance::PREAUTH_APPROVED, 'preauth_reference' => 'SL-1', 'preauth_note' => '']);
        $I->see('Pre-authorisation recorded: pre-auth approved.');
        $I->see('Pre-auth approved', '#preauth-status');
        $I->see('Reference SL-1', '#appointment-insurance');
    }

    public function thePatientSeesTheirInsuranceButCannotRecord(FunctionalTester $I)
    {
        $appointment = $this->insuredBooking();
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('/appointments/view', ['id' => $appointment->id]);
        $I->see('Jubilee', '#appointment-insurance');
        $I->dontSeeElement('#preauth-form');
    }

    public function theBoardFiltersPendingPreauth(FunctionalTester $I)
    {
        $appointment = $this->insuredBooking();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/board/index', ['from' => $this->date, 'to' => $this->date, 'preauth' => Insurance::PREAUTH_PENDING]);
        $I->see('Pre-auth pending', '.preauth-badge');
        $I->amOnRoute('/board/index', ['from' => $this->date, 'to' => $this->date, 'preauth' => Insurance::PREAUTH_APPROVED]);
        $I->dontSeeElement('.preauth-badge');
    }
}
