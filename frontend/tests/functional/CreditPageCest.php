<?php

namespace frontend\tests\functional;

use frontend\models\CommitmentFee;
use frontend\models\PatientCredit;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F23: the patient's credit page, from the link in the message, and asking for a refund.
 */
class CreditPageCest
{
    use BookingDataTrait;

    private function credit($refundable)
    {
        $appointment = $this->book('10:00');
        $fee = new CommitmentFee([
            'appointment_id' => $appointment->id, 'patient_id' => $this->client->id, 'consultant_id' => $this->consultantUser->id,
            'fee_date' => $appointment->date, 'amount' => 250, 'payer' => 'patient', 'state' => CommitmentFee::STATE_CREDITED,
        ]);
        $fee->save(false);
        $credit = new PatientCredit([
            'patient_id' => $this->client->id, 'amount' => 250, 'source_fee_id' => $fee->id,
            'reason' => $refundable ? PatientCredit::REASON_CLINIC_CANCELLED : PatientCredit::REASON_PATIENT_CANCELLED,
            'refundable' => $refundable ? 1 : 0, 'status' => PatientCredit::STATUS_AVAILABLE, 'expires_at' => time() + 90 * 86400,
        ]);
        $credit->save(false);

        return $credit;
    }

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    public function aClinicCancellationCreditCanBeRefunded(FunctionalTester $I)
    {
        $credit = $this->credit(true);
        $I->amOnPage($credit->url());
        $I->see('Ksh 250');
        $I->see('The clinic cancelled');
        $I->submitForm('#credit-refund-form', ['phone' => '0722000111']);

        $I->see('Your refund request is received');
        $credit->refresh();
        $I->assertSame(PatientCredit::STATUS_REFUND_REQUESTED, $credit->status);
        $I->assertSame('254722000111', $credit->refund_phone);
        $I->dontSeeElement('#credit-refund-form');
    }

    public function anEarlyCancellationCreditCannotBeRefunded(FunctionalTester $I)
    {
        $credit = $this->credit(false);
        $I->amOnPage($credit->url());
        $I->see('Cancelled more than 24 hours ahead');
        $I->dontSeeElement('#credit-refund-form');
    }

    public function aWrongSignatureIsRefused(FunctionalTester $I)
    {
        $credit = $this->credit(true);
        $I->amOnPage('/credit/' . $credit->id . '/' . str_repeat('a', 32));
        $I->seeResponseCodeIs(404);
    }
}
