<?php

namespace frontend\tests\unit;

use common\models\User;
use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use frontend\models\FacilityAgreement;
use frontend\services\FeeRules;
use frontend\services\Invoices;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * F25: consultants' monthly invoices: absorbed fees (a statement during the pilot), the plan
 * charge prorated by days on standard terms, Ksh 100 cancellation charges, and the facility copy.
 */
class InvoicesTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var int */
    private $facilityId;
    /** @var string Y-m-d, the first of last month */
    private $start;
    /** @var string Y-m-d, its last day */
    private $end;

    protected function _before()
    {
        $this->createParticipants();
        Yii::$app->params['invoices']['enabled'] = true;
        Yii::$app->params['commitmentFee']['enabled'] = false; // bookings here carry no fee
        $this->facilityId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->start = date('Y-m-01', strtotime('first day of last month'));
        $this->end = date('Y-m-t', strtotime($this->start));
        Invoices::$now = null;
    }

    protected function _after()
    {
        Yii::$app->params['invoices']['enabled'] = false;
        Invoices::$now = null;
        Yii::$app->user->logout();
    }

    private function pilotStartedOn($timestamp)
    {
        Consultant::updateAll(['first_booking_at' => $timestamp], ['id' => $this->consultant->id]);
        $this->consultant = Consultant::findOne($this->consultant->id);
    }

    private function agreement($version = '1.2', $approved = true)
    {
        (new FacilityAgreement([
            'facility_id' => $this->facilityId, 'version' => $version, 'signed_on' => date('Y-m-d', strtotime('-200 days')),
            'facility_signatory' => 'Facility', 'platform_signatory' => 'Platform', 'walk_in_exempt' => 1,
            'standard_terms_confirmed_at' => $approved ? strtotime('-150 days') : null,
            'standard_terms_approved_at' => $approved ? strtotime('-150 days') : null,
        ]))->save(false);
    }

    /** A bare appointment row on $date (no booking rules), with an absorbed fee when asked. */
    private function pastAppointment($date, $absorbedState = null)
    {
        Yii::$app->db->createCommand()->insert('{{%appointments}}', [
            'date' => $date, 'time' => '10:00:00', 'consultant_id' => $this->consultantUser->id,
            'patient_id' => $this->client->id, 'facility_id' => $this->facilityId, 'status' => Appointments::STATUS_COMPLETED,
        ])->execute();
        $id = (int) Yii::$app->db->getLastInsertID();
        if ($absorbedState) {
            (new CommitmentFee([
                'appointment_id' => $id, 'patient_id' => $this->client->id, 'consultant_id' => $this->consultantUser->id,
                'facility_id' => $this->facilityId, 'fee_date' => $date, 'amount' => 250,
                'payer' => CommitmentFee::PAYER_CONSULTANT, 'state' => $absorbedState,
            ]))->save(false);
        }

        return $id;
    }

    private function queued($class, $contains)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', $class])->andWhere(['like', 'job', $contains])->count();
    }

    public function testDuringThePilotTheInvoiceIsAStatementOfAbsorbedFees()
    {
        $this->pilotStartedOn(strtotime($this->start . ' -40 days'));
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +4 days')), CommitmentFee::STATE_ABSORBED);
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +6 days')), CommitmentFee::STATE_FORFEITED);
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +8 days')), CommitmentFee::STATE_CANCELLED);

        $done = Invoices::issueMonth();

        $this->assertCount(1, $done);
        $invoice = ConsultantInvoice::findOne(['consultant_id' => $this->consultant->id]);
        $this->assertSame(500, (int) $invoice->amount, 'absorbed and forfeited are billed; a cancelled fee is not');
        $this->assertTrue($invoice->isStatement());
        $this->assertSame($this->start, $invoice->period_start);
        $this->assertSame(date('Y-m-d', strtotime('+14 days', $invoice->issued_at)), $invoice->due_on);
        $this->assertStringStartsWith('INV-' . date('Ym', strtotime($this->start)) . '-C', $invoice->number);
        $this->assertSame(1, $this->queued('SendEmailMessageJob', $invoice->number));
        $this->assertSame(1, $this->queued('SendWhatsappTextJob', $invoice->number));

        $this->assertSame([], Invoices::issueMonth(), 'a month is issued once');
    }

    public function testThePlanChargeIsProratedInTheMonthStandardTermsStart()
    {
        $this->agreement();
        // Day 91 falls on the 10th of last month
        $this->pilotStartedOn(strtotime(date('Y-m-d', strtotime($this->start . ' +9 days')) . ' 09:00 -90 days'));
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +15 days')));

        Invoices::issueMonth();

        $inMonth = (int) date('t', strtotime($this->start));
        $days = $inMonth - 9;
        $line = ConsultantInvoiceLine::findOne(['consultant_id' => $this->consultant->id, 'type' => ConsultantInvoiceLine::TYPE_PLAN]);
        $this->assertSame((int) round(1000 * $days / $inMonth), (int) $line->amount);
        $this->assertStringContainsString('Plan A: commitment fee, Ksh 1,000 a month', $line->description);
        $this->assertStringContainsString("prorated for {$days} of {$inMonth} days on standard terms", $line->description);
        $this->assertFalse(ConsultantInvoice::findOne(['consultant_id' => $this->consultant->id])->isStatement());
    }

    public function testAFullMonthOnPlanBIsChargedInFull()
    {
        $this->agreement();
        $this->pilotStartedOn(strtotime('-300 days'));
        Consultant::updateAll(['plan' => FeeRules::PLAN_B], ['id' => $this->consultant->id]);
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +3 days')));

        Invoices::issueMonth();

        $line = ConsultantInvoiceLine::findOne(['consultant_id' => $this->consultant->id, 'type' => ConsultantInvoiceLine::TYPE_PLAN]);
        $this->assertSame(1500, (int) $line->amount);
        $this->assertStringNotContainsString('prorated', $line->description);
    }

    public function testNoPlanChargeWithoutAnAppointmentInTheMonthOrBeforeApproval()
    {
        $this->pilotStartedOn(strtotime('-300 days'));
        $this->agreement('1.2', false); // confirmed by nobody, approved by nobody
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +3 days')));
        $this->assertSame([], Invoices::issueMonth(), 'terms not approved: nothing to bill');

        FacilityAgreement::updateAll(['standard_terms_approved_at' => strtotime('-150 days')]);
        Appointments::deleteAll(['consultant_id' => $this->consultantUser->id]);
        $this->assertSame([], Invoices::issueMonth(), 'no appointment in the month: not an active consultant');
    }

    public function testAVoidedMonthIssuedAgainDoesNotChargeThePlanTwice()
    {
        $this->agreement();
        $this->pilotStartedOn(strtotime('-300 days'));
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +3 days')));
        Invoices::issueMonth();
        $invoice = ConsultantInvoice::findOne(['consultant_id' => $this->consultant->id]);
        $this->assertTrue(Invoices::void($invoice, 'Wrong plan'));

        $this->assertSame([], Invoices::issueMonth());
        $this->assertSame(1, (int) ConsultantInvoiceLine::find()->where(['type' => ConsultantInvoiceLine::TYPE_PLAN])->count());
    }

    public function testCancellingOnStandardTermsChargesTheConsultantAndTellsThem()
    {
        $this->agreement();
        $this->pilotStartedOn(strtotime('-120 days'));
        $appointment = $this->newBooking('10:00');
        $appointment->saveBooking();
        Yii::$app->user->login($this->consultantUser);

        $this->assertTrue($appointment->cancel(null, Appointments::REASON_CLINIC));

        $line = ConsultantInvoiceLine::findOne(['type' => ConsultantInvoiceLine::TYPE_CANCELLATION, 'source_id' => $appointment->id]);
        $this->assertSame(100, (int) $line->amount);
        $this->assertNull($line->invoice_id, 'waits for the monthly invoice');
        $this->assertStringContainsString('cancelled by you', $line->description);
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Ksh 100 cancellation charge'));
    }

    public function testNoCancellationChargeAtThePatientsRequestOnV11OrDuringThePilot()
    {
        $this->pilotStartedOn(strtotime('-120 days'));
        $this->agreement();
        Yii::$app->user->login($this->consultantUser);
        $asked = $this->newBooking('10:00');
        $asked->saveBooking();
        $asked->cancel(null, Appointments::REASON_PATIENT_REQUEST);
        $closed = $this->newBooking('11:00');
        $closed->saveBooking();
        $closed->cancel(null, Appointments::REASON_FACILITY_CLOSED);

        FacilityAgreement::updateAll(['version' => '1.1.0']);
        $older = $this->newBooking('12:00');
        $older->saveBooking();
        $older->cancel(null, Appointments::REASON_CLINIC);

        FacilityAgreement::updateAll(['version' => '1.2']);
        $this->pilotStartedOn(strtotime('-60 days'));
        $pilot = $this->newBooking('09:00');
        $pilot->saveBooking();
        $pilot->cancel(null, Appointments::REASON_CLINIC);

        $this->assertFalse(ConsultantInvoiceLine::find()->where(['type' => ConsultantInvoiceLine::TYPE_CANCELLATION])->exists());
    }

    public function testFacilityAdminsGetTheLinesFromTheirFacility()
    {
        $admin = $this->createUser('facility.boss', User::ACCOUNT_FACILITY_ADMIN);
        $admin->updateAttributes(['facility_id' => $this->facilityId]);
        $this->pilotStartedOn(strtotime($this->start . ' -40 days'));
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +4 days')), CommitmentFee::STATE_ABSORBED);

        Invoices::issueMonth();

        $this->assertSame(1, (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])
            ->andWhere(['like', 'job', 'facility.boss@example.com'])->andWhere(['like', 'job', 'nothing is due from the facility'])->count());
    }

    public function testWaivingALineLowersTheTotalAndRecordingPaysIt()
    {
        $this->agreement();
        $this->pilotStartedOn(strtotime('-300 days'));
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +3 days')), CommitmentFee::STATE_ABSORBED);
        Invoices::issueMonth();
        $invoice = ConsultantInvoice::findOne(['consultant_id' => $this->consultant->id]);
        $this->assertSame(1250, (int) $invoice->amount);

        $plan = ConsultantInvoiceLine::findOne(['invoice_id' => $invoice->id, 'type' => ConsultantInvoiceLine::TYPE_PLAN]);
        $this->assertFalse(Invoices::waive($plan, ''), 'a reason is needed');
        $this->assertTrue(Invoices::waive($plan, 'Goodwill'));
        $invoice->refresh();
        $this->assertSame(250, (int) $invoice->amount);
        $this->assertTrue($invoice->isStatement(), 'only absorbed fees left');

        $this->assertTrue(Invoices::markPaid($invoice, 'ukg8420xho', $this->admin->id));
        $this->assertSame('UKG8420XHO', $invoice->reference);
        $this->assertFalse(Invoices::waive(ConsultantInvoiceLine::findOne(['invoice_id' => $invoice->id, 'waived' => 0]), 'Too late'));
    }

    public function testNothingHappensWhileInvoicesAreOff()
    {
        Yii::$app->params['invoices']['enabled'] = false;
        $this->pilotStartedOn(strtotime($this->start . ' -40 days'));
        $this->pastAppointment(date('Y-m-d', strtotime($this->start . ' +4 days')), CommitmentFee::STATE_ABSORBED);

        $this->assertSame(['Invoices are off (INVOICES_ENABLED=0).'], Invoices::issueMonth());
        $this->assertFalse(ConsultantInvoice::find()->exists());
    }
}
