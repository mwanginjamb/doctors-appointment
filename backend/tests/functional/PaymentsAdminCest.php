<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\models\User;
use frontend\models\IncentivePayout;
use frontend\models\PatientCredit;
use Yii;

/**
 * F23/F24 in the admin area: deciding refund requests and paying the weekly front-desk payouts.
 * B2C is off, as until live credentials are in: refunds and payouts are paid by hand and recorded.
 */
class PaymentsAdminCest
{
    /** @var User */
    private $admin;
    /** @var User */
    private $patient;
    /** @var User */
    private $frontDesk;

    private function user($username, $role, $phone = null)
    {
        $user = new User(['username' => $username, 'email' => "$username@example.com", 'full_name' => ucwords(str_replace('.', ' ', $username)),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'phone_number' => $phone]);
        $user->setPassword('password_0');
        $user->generateAuthKey();
        $user->save(false);

        return $user;
    }

    public function _before(FunctionalTester $I)
    {
        Yii::$app->params['payouts.b2cEnabled'] = false;
        $this->admin = $this->user('platform.admin', 'admin');
        $this->patient = $this->user('patient.one', 'client', '0712345678');
        $this->frontDesk = $this->user('front.desk', 'front_desk', '0711222333');
    }

    private function refundRequest()
    {
        $credit = new PatientCredit([
            'patient_id' => $this->patient->id, 'amount' => 250, 'source_fee_id' => 900000 + $this->patient->id,
            'reason' => PatientCredit::REASON_CLINIC_CANCELLED, 'refundable' => 1, 'status' => PatientCredit::STATUS_REFUND_REQUESTED,
            'expires_at' => time() + 80 * 86400, 'refund_requested_at' => time(), 'refund_phone' => '254712345678',
        ]);
        $credit->save(false);

        return $credit;
    }

    private function payout($status = IncentivePayout::STATUS_READY)
    {
        $payout = new IncentivePayout([
            'front_desk_id' => $this->frontDesk->id, 'period_start' => date('Y-m-d', strtotime('monday last week')),
            'period_end' => date('Y-m-d', strtotime('sunday last week')), 'amount' => 150, 'incentives' => 3,
            'status' => $status, 'phone' => $status === IncentivePayout::STATUS_READY ? '254711222333' : null,
        ]);
        $payout->save(false);

        return $payout;
    }

    public function anAdminApprovesAndRecordsARefund(FunctionalTester $I)
    {
        $credit = $this->refundRequest();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/refund/index');
        $I->see('Patient One');
        $I->see('254712345678');

        $I->submitForm('form[action$="/refund/approve?id=' . $credit->id . '"]', []);
        $I->see('approved. Pay Ksh 250 to 254712345678');

        $I->submitForm('form[action$="/refund/record?id=' . $credit->id . '"]', ['reference' => 'ukg8420xho']);
        $I->see('recorded as paid (UKG8420XHO)');
        $credit->refresh();
        $I->assertSame(PatientCredit::STATUS_REFUNDED, $credit->status);
        $I->assertSame($this->admin->id, (int) $credit->refund_decided_by);
    }

    public function anAdminDeclinesARefundWithAReason(FunctionalTester $I)
    {
        $credit = $this->refundRequest();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/refund/index');
        $I->submitForm('form[action$="/refund/reject?id=' . $credit->id . '"]', ['reason' => 'Asked twice']);

        $I->see('declined; the credit stays usable');
        $credit->refresh();
        $I->assertSame(PatientCredit::STATUS_REFUND_REJECTED, $credit->status);
    }

    public function anAdminRecordsAPayoutPaidByHand(FunctionalTester $I)
    {
        $payout = $this->payout();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/payout/index');
        $I->see('Front Desk');
        $I->see('Ksh 150');
        $I->dontSee('Send by M-Pesa', 'button'); // B2C is off

        $I->submitForm('form[action$="/payout/record?id=' . $payout->id . '"]', ['reference' => 'QWE1234567']);
        $I->see('recorded as paid (QWE1234567)');
        $payout->refresh();
        $I->assertSame(IncentivePayout::STATUS_PAID, $payout->status);
    }

    public function aHeldPayoutIsReadyOnceThePhoneIsFixed(FunctionalTester $I)
    {
        $payout = $this->payout(IncentivePayout::STATUS_HELD);
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/payout/index');
        $I->submitForm('form[action$="/payout/recheck?id=' . $payout->id . '"]', []);

        $I->see('is ready: 254711222333');
        $payout->refresh();
        $I->assertSame(IncentivePayout::STATUS_READY, $payout->status);
    }

    public function staffSignedInElsewhereAreRefused(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('/payout/index');
        $I->seeResponseCodeIs(403);
    }
}
