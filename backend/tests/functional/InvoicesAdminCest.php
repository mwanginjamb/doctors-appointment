<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\models\User;
use frontend\models\Consultant;
use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use Yii;

/**
 * F25 in the admin area: listing invoices, recording a payment, waiving a line, voiding.
 */
class InvoicesAdminCest
{
    /** @var User */
    private $admin;
    /** @var ConsultantInvoice */
    private $invoice;
    /** @var ConsultantInvoiceLine */
    private $cancellation;

    private function user($username, $role)
    {
        $user = new User(['username' => $username, 'email' => "$username@example.com", 'full_name' => ucwords(str_replace('.', ' ', $username)),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
        $user->setPassword('password_0');
        $user->generateAuthKey();
        $user->save(false);

        return $user;
    }

    public function _before(FunctionalTester $I)
    {
        Yii::$app->params['invoices']['enabled'] = true;
        $this->admin = $this->user('platform.admin', 'admin');
        $doctor = $this->user('dr.billed', 'consultant');
        $consultant = new Consultant(['user_id' => $doctor->id, 'names' => 'Dr. Billed', 'consultant_email' => 'dr.billed@example.com', 'consultant_phone_number' => '0712000010']);
        $consultant->save(false);

        $start = date('Y-m-01', strtotime('first day of last month'));
        $this->invoice = new ConsultantInvoice([
            'number' => 'INV-' . date('Ym', strtotime($start)) . '-C9999', 'consultant_id' => $consultant->id,
            'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)), 'amount' => 1100,
            'status' => ConsultantInvoice::STATUS_ISSUED, 'pausable' => 1, 'issued_at' => strtotime('-20 days'),
            'due_on' => date('Y-m-d', strtotime('-6 days')),
        ]);
        $this->invoice->save(false);
        foreach ([[ConsultantInvoiceLine::TYPE_PLAN, 1000, 'Plan A: commitment fee, Ksh 1,000 a month'], [ConsultantInvoiceLine::TYPE_CANCELLATION, 100, 'Booking #5 cancelled by you']] as [$type, $amount, $text]) {
            $line = new ConsultantInvoiceLine(['consultant_id' => $consultant->id, 'invoice_id' => $this->invoice->id, 'type' => $type,
                'source_id' => $type === ConsultantInvoiceLine::TYPE_PLAN ? null : 5, 'line_date' => $start, 'description' => $text, 'amount' => $amount]);
            $line->save(false);
            $this->cancellation = $line;
        }
    }

    public function _after(FunctionalTester $I)
    {
        Yii::$app->params['invoices']['enabled'] = false;
    }

    public function homeAndListShowTheOverdueInvoice(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/site/index');
        $I->see('1', '#count-overdue');

        $I->amOnRoute('/invoice/index', ['status' => 'overdue']);
        $I->see($this->invoice->number);
        $I->see('Dr. Billed');
        $I->see('1,100');
    }

    public function anAdminWaivesALineThenRecordsThePayment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/invoice/view', ['id' => $this->invoice->id]);
        $I->see('Plan A: commitment fee, Ksh 1,000 a month');

        $I->submitForm('#line-' . $this->cancellation->id . ' form', ['reason' => 'The patient had asked']);
        $I->see('Line waived; the invoice is now Ksh 1,000.');
        $I->see('Waived: The patient had asked');

        $I->submitForm('#record-form', ['reference' => 'qwe1234567']);
        $I->see('recorded as paid (QWE1234567)');
        $this->invoice->refresh();
        $I->assertSame(ConsultantInvoice::STATUS_PAID, $this->invoice->status);
        $I->assertSame($this->admin->id, (int) $this->invoice->paid_by);
        $I->dontSeeElement('#void-form');
    }

    public function anAdminVoidsAnInvoice(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/invoice/view', ['id' => $this->invoice->id]);
        $I->submitForm('#void-form', ['reason' => 'Issued to the wrong consultant']);
        $I->see('is void; nothing is due on it.');
        $I->assertSame(0, (int) ConsultantInvoiceLine::find()->where(['invoice_id' => $this->invoice->id, 'waived' => 0])->count());
    }

    public function issuingWhileOffSaysSo(FunctionalTester $I)
    {
        Yii::$app->params['invoices']['enabled'] = false;
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/invoice/index');
        $I->see('Invoices are off (INVOICES_ENABLED=0)');
    }
}
