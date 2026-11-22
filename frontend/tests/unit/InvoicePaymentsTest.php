<?php

namespace frontend\tests\unit;

use common\components\MpesaException;
use common\models\MpesaTransaction;
use common\models\User;
use frontend\models\FrontDeskAssignment;
use frontend\models\CalendarViewModel;
use frontend\models\Consultant;
use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use frontend\models\SlotFinder;
use frontend\models\WaitlistForm;
use frontend\services\Invoices;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;
use yii\helpers\Json;

/**
 * F25 part 2: paying an invoice by M-Pesa prompt, reminders on the due date and a week later, and
 * pausing patients' online booking with a consultant whose invoice is long overdue.
 */
class InvoicePaymentsTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var array path => list of [status, body] */
    private $answers = [];
    /** @var array */
    private $requests = [];
    /** @var callable */
    private $originalTransport;

    protected function _before()
    {
        $this->createParticipants();
        Yii::$app->params['invoices']['enabled'] = true;
        Yii::$app->params['commitmentFee']['enabled'] = false;
        Yii::$app->cache->flush();
        Invoices::$now = null;

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
        Yii::$app->params['invoices']['enabled'] = false;
        Yii::$app->mpesa->transport = $this->originalTransport;
        Invoices::$now = null;
        Yii::$app->user->logout();
    }

    private function answer($path, array $body, $status = 200)
    {
        $this->answers[$path][] = [$status, Json::encode($body)];
    }

    private function answerStkAccepted()
    {
        $this->answer('/mpesa/stkpush/v1/processrequest', [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1',
            'ResponseCode' => '0', 'ResponseDescription' => 'Accepted', 'CustomerMessage' => 'Accepted',
        ]);
    }

    private function requestsTo($path)
    {
        return array_values(array_filter($this->requests, function ($r) use ($path) { return $r['path'] === $path; }));
    }

    private function queued($class, $contains)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', $class])->andWhere(['like', 'job', $contains])->count();
    }

    /** An unpaid invoice due $dueInDays from today (negative: overdue), with plan or absorbed lines. */
    private function invoice($dueInDays, $pausable = true, $amount = 1100, $suffix = '')
    {
        $start = date('Y-m-01', strtotime('first day of last month'));
        $invoice = new ConsultantInvoice([
            'number' => 'INV-' . date('Ym', strtotime($start)) . '-C' . str_pad((string) $this->consultant->id, 4, '0', STR_PAD_LEFT) . $suffix,
            'consultant_id' => $this->consultant->id, 'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)),
            'amount' => $amount, 'status' => ConsultantInvoice::STATUS_ISSUED, 'pausable' => $pausable ? 1 : 0,
            'issued_at' => strtotime(($dueInDays - 14) . ' days'), 'due_on' => date('Y-m-d', strtotime($dueInDays . ' days')),
        ]);
        $invoice->save(false);
        (new ConsultantInvoiceLine([
            'consultant_id' => $this->consultant->id, 'invoice_id' => $invoice->id,
            'type' => $pausable ? ConsultantInvoiceLine::TYPE_PLAN : ConsultantInvoiceLine::TYPE_ABSORBED,
            'source_id' => $pausable ? null : 900, 'line_date' => $start, 'description' => 'A charge', 'amount' => $amount,
        ]))->save(false);

        return $invoice;
    }

    private function pauseConsultant()
    {
        Consultant::updateAll(['online_booking_paused_at' => time()], ['id' => $this->consultant->id]);
        $this->consultant = Consultant::findOne($this->consultant->id);
    }

    private function isPaused()
    {
        return Consultant::findOne($this->consultant->id)->isOnlineBookingPaused();
    }

    public function testPayingByPromptMarksTheInvoicePaidAndLiftsThePause()
    {
        $invoice = $this->invoice(-20);
        $this->pauseConsultant();
        $this->answerStkAccepted();

        $this->assertNull(Invoices::sendPrompt($invoice, '0712 999 888'));
        $push = $this->requestsTo('/mpesa/stkpush/v1/processrequest')[0]['body'];
        $this->assertSame(1100, (int) $push['Amount']);
        $this->assertSame('254712999888', (string) $push['PhoneNumber']);
        $this->assertSame('INV' . $invoice->id, $push['AccountReference']);
        $invoice->refresh();
        $this->assertSame('254712999888', $invoice->pay_phone);
        $this->assertTrue(Invoices::statusOf($invoice)['promptPending']);

        // M-Pesa says it is paid; the settled event marks the invoice paid
        $this->answer('/mpesa/stkpushquery/v1/query', ['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']);
        Yii::$app->mpesa->refreshStk(MpesaTransaction::findOne($invoice->mpesa_transaction_id));

        $invoice->refresh();
        $this->assertSame(ConsultantInvoice::STATUS_PAID, $invoice->status);
        $this->assertNotEmpty($invoice->reference);
        $this->assertNull($invoice->paid_by, 'paid by the consultant, not recorded by an admin');
        $this->assertFalse($this->isPaused(), 'paying lifts the pause');
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Paid: ' . $invoice->number));
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Online booking is back on'));
    }

    public function testTheInvoiceEmailCarriesPayViaMpesaAndTheFrontDeskIsAskedToRemind()
    {
        $desk = $this->createUser('desk.one', User::ACCOUNT_FRONT_DESK);
        FrontDeskAssignment::replaceFor($desk->id, [$this->consultant->id]);
        $invoice = $this->invoice(0);

        Invoices::send($invoice);
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Pay via M-Pesa'), 'the button in the consultant email');
        $this->assertSame(1, $this->queued('SendEmailMessageJob', $invoice->signature() . '/pay'), 'the pay page, in the consultant email only');
        $this->assertSame(1, $this->queued('SendWhatsappTextJob', $invoice->signature() . '/pay'));
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Please remind Dr. Test: invoice ' . $invoice->number));
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'desk.one@example.com'));
        $this->assertSame([], $this->requestsTo('/mpesa/stkpush/v1/processrequest'), 'sending the invoice sends no prompt');

        // The due-date reminder asks the front desk again
        Invoices::remindAndPause();
        $this->assertSame(2, $this->queued('SendEmailMessageJob', 'Please remind Dr. Test: invoice ' . $invoice->number));
        $this->assertSame(2, $this->queued('SendEmailMessageJob', 'Pay via M-Pesa'));

        // Nothing to remind about once it is paid
        Invoices::markPaid($invoice, 'QWE1234567');
        Invoices::send($invoice, true);
        $this->assertSame(2, $this->queued('SendEmailMessageJob', 'Please remind Dr. Test: invoice ' . $invoice->number));
    }

    public function testAPromptThatDoesNotGoThroughLeavesTheInvoiceOpenWithTheReason()
    {
        $invoice = $this->invoice(3);
        $this->answerStkAccepted();
        $this->assertNull(Invoices::sendPrompt($invoice));
        $invoice->refresh();
        $this->assertSame('254712000000', $invoice->pay_phone, "the consultant's own number by default");

        $this->answer('/mpesa/stkpushquery/v1/query', ['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user']);
        Yii::$app->mpesa->refreshStk(MpesaTransaction::findOne($invoice->mpesa_transaction_id));

        $invoice->refresh();
        $this->assertTrue($invoice->isOpen());
        $this->assertSame('Request cancelled by user', $invoice->pay_note);
        $this->assertFalse(Invoices::statusOf($invoice)['promptPending']);
    }

    public function testAPromptIsRefusedWhenNothingIsDueTheNumberIsWrongOrOneWasJustSent()
    {
        $invoice = $this->invoice(3);
        $this->assertStringContainsString('Safaricom', Invoices::sendPrompt($invoice, '12345'));

        $this->answerStkAccepted();
        $this->assertNull(Invoices::sendPrompt($invoice));
        $invoice->refresh();
        $this->assertStringContainsString('less than a minute ago', Invoices::sendPrompt($invoice));
        $this->assertCount(1, $this->requestsTo('/mpesa/stkpush/v1/processrequest'));

        Invoices::markPaid($invoice, 'QWE1234567');
        $this->assertStringContainsString('Nothing is due', Invoices::sendPrompt($invoice));
    }

    public function testRemindersOnTheDueDateAndAWeekLater()
    {
        $invoice = $this->invoice(0, false, 250);

        $this->assertSame(["{$invoice->number}: first reminder"], Invoices::remindAndPause());
        $this->assertSame([], Invoices::remindAndPause(), 'once a day at most');
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Due today: ' . $invoice->number));
        $this->assertSame(1, $this->queued('SendWhatsappTextJob', 'is due today'));

        Invoices::$now = strtotime('+7 days');
        $this->assertSame(["{$invoice->number}: second reminder"], Invoices::remindAndPause());
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Overdue: ' . $invoice->number));
        $this->assertSame(0, $this->queued('SendEmailMessageJob', 'will not be able to book you online'), 'a statement never pauses');

        Invoices::$now = strtotime('+30 days');
        $this->assertSame([], Invoices::remindAndPause(), 'two reminders, and a statement is never paused');
        $this->assertFalse($this->isPaused());
    }

    public function testAnInvoiceWithStandardTermsChargesPausesOnlineBooking14DaysOverdue()
    {
        $invoice = $this->invoice(-13);
        $invoice->updateAttributes(['reminders_sent' => 2]);
        $this->assertSame([], Invoices::remindAndPause(), 'not yet: 13 days overdue');

        Invoices::$now = strtotime('+1 day');
        $this->assertSame(['Paused online booking: Dr. Test'], Invoices::remindAndPause());
        $this->assertTrue($this->isPaused());
        $this->assertSame(1, $this->queued('SendEmailMessageJob', 'Online booking paused: invoice overdue'));
        $this->assertSame([], Invoices::remindAndPause(), 'paused once');
    }

    public function testWhilePausedPatientsCannotBookOnlineButStaffCanAndMovesGoOn()
    {
        $this->pauseConsultant();

        Yii::$app->user->login($this->client);
        $online = $this->newBooking('10:00');
        $this->assertFalse($online->saveBooking());
        $this->assertStringContainsString("Online booking with Dr. Test isn't available right now", json_encode($online->errors));

        $calendar = new CalendarViewModel(['viewer' => $this->client, 'consultant' => $this->consultant]);
        $this->assertTrue($calendar->paused);
        $this->assertTrue($calendar->readOnly);
        $this->assertSame([], (new WaitlistForm(['consultant' => $this->consultant, 'patient' => $this->client]))->facilityChoices(), 'no joining the waiting list');

        // A patient moving a booking they have still sees times
        $slots = (new SlotFinder(['consultant' => $this->consultant, 'from' => $this->date, 'to' => $this->date, 'onlineOnly' => true,
            'notBefore' => strtotime($this->date . ' 00:00')]))->find();
        $this->assertNotEmpty($slots[$this->date] ?? []);

        Yii::$app->user->logout();
        Yii::$app->user->login($this->consultantUser);
        $this->assertTrue($this->newBooking('11:00')->saveBooking(), 'the consultant and their front desk still book');
        $this->assertFalse((new CalendarViewModel(['viewer' => $this->consultantUser, 'consultant' => $this->consultant]))->paused);
    }

    public function testVoidingOrWaivingTheOverdueInvoiceLiftsThePause()
    {
        $invoice = $this->invoice(-20);
        $this->pauseConsultant();
        Invoices::void($invoice, 'Issued in error');
        $this->assertFalse($this->isPaused());

        $other = $this->invoice(-20, true, 1100, '-2');
        $this->pauseConsultant();
        Invoices::waive(ConsultantInvoiceLine::findOne(['invoice_id' => $other->id]), 'Agreed with the consultant');
        $this->assertFalse($this->isPaused(), 'nothing chargeable is left');
    }
}
