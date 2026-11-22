<?php

namespace frontend\tests\functional;

use frontend\models\Consultant;
use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F25: the consultant's invoice page (/invoice/<id>/<signature>, no sign-in) and My invoices.
 */
class InvoicePageCest
{
    use BookingDataTrait;

    /** @var ConsultantInvoice */
    private $invoice;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $start = date('Y-m-01', strtotime('first day of last month'));
        $this->invoice = new ConsultantInvoice([
            'number' => 'INV-' . date('Ym', strtotime($start)) . '-C0001', 'consultant_id' => $this->consultant->id,
            'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)), 'amount' => 250,
            'status' => ConsultantInvoice::STATUS_ISSUED, 'pausable' => 0, 'issued_at' => time(), 'due_on' => date('Y-m-d', strtotime('+14 days')),
        ]);
        $this->invoice->save(false);
        (new ConsultantInvoiceLine(['consultant_id' => $this->consultant->id, 'invoice_id' => $this->invoice->id,
            'type' => ConsultantInvoiceLine::TYPE_ABSORBED, 'source_id' => 77, 'line_date' => $start,
            'description' => 'Commitment fee absorbed for an insured patient: booking #77', 'amount' => 250]))->save(false);
    }

    public function theSignedLinkShowsTheInvoiceWithoutSigningIn(FunctionalTester $I)
    {
        $I->amOnPage($this->invoice->url());
        $I->seeResponseCodeIs(200);
        $I->see('Statement ' . $this->invoice->number);
        $I->see('Commitment fee absorbed for an insured patient: booking #77', '#invoice-lines');
        $I->see('250', '#invoice-total');
        $I->see('Ksh 250 due by', '#invoice-status');
    }

    public function theInvoiceLinksToItsPayViaMpesaPage(FunctionalTester $I)
    {
        $I->amOnPage($this->invoice->url());
        $I->see('Pay Ksh 250 via M-Pesa', '#pay-link');
        $I->dontSeeElement('#booking-paused');

        // Opening the email's button only shows the page: one tap sends the prompt
        $I->amOnPage($this->invoice->payUrl());
        $I->seeResponseCodeIs(200);
        $I->see('Ksh 250', '#pay-amount');
        $I->see('The prompt goes to 0712 *** 000.', '#pay-to');
        $I->see('Send the M-Pesa prompt', '#pay-form');
        $I->assertNull(ConsultantInvoice::findOne($this->invoice->id)->mpesa_transaction_id, 'no prompt on opening the link');
    }

    public function thePayPageOfAPaidInvoiceShowsTheInvoice(FunctionalTester $I)
    {
        $this->invoice->updateAttributes(['status' => ConsultantInvoice::STATUS_PAID, 'reference' => 'QWE1234567', 'paid_at' => time()]);
        $I->amOnPage($this->invoice->payUrl());
        $I->see('Paid', '#invoice-status');
        $I->dontSeeElement('#pay-form');
    }

    public function aPausedConsultantIsToldOnTheInvoiceAndPatientsAreToldToCall(FunctionalTester $I)
    {
        Consultant::updateAll(['online_booking_paused_at' => time()], ['id' => $this->consultant->id]);
        $I->amOnPage($this->invoice->url());
        $I->see('Patients cannot book you online until your overdue invoice is paid', '#booking-paused');

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);
        $I->see("Online booking with Dr. Test isn't available right now.");
        $I->seeElement('#booking-paused');
        $I->see('to book', '#booking-paused');
        $I->dontSee('overdue');
        $I->dontSee('invoice');
    }

    public function aWrongSignatureIsRefused(FunctionalTester $I)
    {
        $I->amOnPage('/invoice/' . $this->invoice->id . '/' . str_repeat('0', 32));
        $I->seeResponseCodeIs(404);
    }

    public function theConsultantListsTheirInvoices(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('invoice/index');
        $I->see($this->invoice->number, '#invoices');
        $I->see('My invoices');
    }

    public function patientsCannotListInvoices(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('invoice/index');
        $I->seeResponseCodeIs(403);
    }
}
