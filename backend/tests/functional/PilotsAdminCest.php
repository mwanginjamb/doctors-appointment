<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\models\MpesaTransaction;
use common\models\User;
use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use frontend\services\FeeRules;
use frontend\services\Pilot;
use Yii;

/**
 * F28 in the admin area: facilities' agreements and standard terms, consultants' plans and pilots,
 * and the payments list.
 */
class PilotsAdminCest
{
    /** @var User */
    private $admin;
    /** @var Facility */
    private $facility;
    /** @var Consultant */
    private $consultant;

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
        $this->admin = $this->user('platform.admin', 'admin');
        $this->facility = new Facility(['name' => 'Pilot Clinic', 'active' => true]);
        $this->facility->save(false);
        $doctor = $this->user('dr.pilot', 'consultant');
        $this->consultant = new Consultant(['user_id' => $doctor->id, 'names' => 'Dr. Pilot', 'facility' => 'Pilot Clinic',
            'consultant_email' => 'dr.pilot@example.com', 'consultant_phone_number' => '0712000009']);
        $this->consultant->save(false); // engaged at Pilot Clinic, from the profile
        // Day 85 of the pilot: standard terms are upcoming
        $this->consultant->updateAttributes(['first_booking_at' => strtotime('-84 days')]);
    }

    private function agreement(array $attributes = [])
    {
        $agreement = new FacilityAgreement($attributes + [
            'facility_id' => $this->facility->id, 'version' => '1.2', 'signed_on' => date('Y-m-d', strtotime('-100 days')),
            'facility_signatory' => 'Jane Admin, Director', 'platform_signatory' => 'Platform Lead', 'walk_in_exempt' => 1,
        ]);
        $agreement->save(false);

        return $agreement;
    }

    public function homeShowsFacilitiesWithoutAnAgreement(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/site/index');
        $I->see('Facilities without an agreement');
        $I->see('Pilot Clinic', '.card');
    }

    public function anAdminRecordsASignedAgreement(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/index');
        $I->see('Pilot Clinic');
        $I->see('None recorded');

        $I->amOnRoute('/pilot/facility', ['id' => $this->facility->id]);
        $I->submitForm('#agreement-form', [
            'FacilityAgreement[version]' => '1.2',
            'FacilityAgreement[signed_on]' => '2026-11-01',
            'FacilityAgreement[facility_signatory]' => 'Jane Admin, Director',
            'FacilityAgreement[platform_signatory]' => 'Platform Lead',
        ]);
        $I->see('Agreement v1.2 signed on 2026-11-01 recorded for Pilot Clinic');
        $agreement = FacilityAgreement::currentFor($this->facility->id);
        $I->assertSame('Jane Admin, Director', $agreement->facility_signatory);
        $I->assertSame($this->admin->id, (int) $agreement->created_by);
    }

    public function aNewAgreementKeepsTheHistoryAndTheNoticesSent(FunctionalTester $I)
    {
        $old = $this->agreement(['version' => '1.1.0', 'notice_upcoming_sent_at' => time() - 3600]);
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/facility', ['id' => $this->facility->id]);
        $I->submitForm('#agreement-form', [
            'FacilityAgreement[version]' => '1.2',
            'FacilityAgreement[signed_on]' => date('Y-m-d'),
            'FacilityAgreement[facility_signatory]' => 'Jane Admin, Director',
            'FacilityAgreement[platform_signatory]' => 'Platform Lead',
        ]);

        $current = FacilityAgreement::currentFor($this->facility->id);
        $I->assertNotEquals($old->id, $current->id);
        $I->assertSame((int) $old->notice_upcoming_sent_at, (int) $current->notice_upcoming_sent_at, 'the day 81 notice is not sent again');
        $I->see('v1.1.0', '#agreements');
        $I->see('current', '#agreements');
    }

    public function anAdminApprovesConfirmedStandardTerms(FunctionalTester $I)
    {
        $this->agreement(['standard_terms_confirmed_at' => time() - 60]);
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/site/index');
        $I->see('1', '#count-approvals');

        $I->amOnRoute('/pilot/index');
        $I->submitForm('form[action$="/pilot/approve?id=' . $this->facility->id . '"]', []);
        $I->see('Standard terms approved for Pilot Clinic');
        $I->assertSame(Pilot::TERMS_APPROVED, Pilot::termsStatus($this->facility->id));
        $I->assertSame($this->admin->id, (int) FacilityAgreement::currentFor($this->facility->id)->standard_terms_approved_by);
    }

    public function anAdminConfirmsOnTheFacilitysWord(FunctionalTester $I)
    {
        $this->agreement();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/facility', ['id' => $this->facility->id]);
        $I->submitForm('form[action$="/pilot/confirm?id=' . $this->facility->id . '"]', []);
        $I->see('Standard terms confirmed for Pilot Clinic');
        $I->assertSame(Pilot::TERMS_AWAITING_APPROVAL, Pilot::termsStatus($this->facility->id));
    }

    public function anAdminChangesAPlan(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/consultants');
        $I->see('Dr. Pilot');
        $I->see('85', '#consultant-' . $this->consultant->id);

        $I->submitForm('form[action$="/pilot/plan?id=' . $this->consultant->id . '"]', ['plan' => FeeRules::PLAN_A, 'insured_pay' => '0']);
        $I->see('Dr. Pilot: Plan A: commitment fee, absorbs it for insured patients.');
        $this->consultant->refresh();
        $I->assertSame(0, (int) $this->consultant->insured_pay);

        $I->submitForm('form[action$="/pilot/plan?id=' . $this->consultant->id . '"]', ['plan' => FeeRules::PLAN_B]);
        $this->consultant->refresh();
        $I->assertSame(FeeRules::PLAN_B, $this->consultant->plan);
    }

    public function anAdminRestartsAPilot(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/facility', ['id' => $this->facility->id]);
        $I->submitForm('#consultant-' . $this->consultant->id . ' form[action$="/pilot/restart?id=' . $this->consultant->id . '"]', []);
        $I->see("Dr. Pilot's pilot starts again at their next booking.");
        $this->consultant->refresh();
        $I->assertNull($this->consultant->first_booking_at);
    }

    public function paymentsListFeesWithTheirReceipts(FunctionalTester $I)
    {
        $patient = $this->user('patient.pay', 'client');
        $tx = new MpesaTransaction(['kind' => 'stk', 'status' => 'succeeded', 'phone' => '254712345678', 'amount' => 250,
            'reference' => 'FEE1', 'description' => 'Commitment fee', 'receipt' => 'UKG8420XHO']);
        $tx->save(false);
        foreach ([[CommitmentFee::STATE_PAID, $tx->id], [CommitmentFee::STATE_FORFEITED, null]] as $i => [$state, $txId]) {
            // A bare appointment row: the fee's foreign key needs one, not the booking rules
            Yii::$app->db->createCommand()->insert('{{%appointments}}', [
                'date' => date('Y-m-d'), 'time' => sprintf('%02d:00:00', 9 + $i), 'consultant_id' => $this->consultant->user_id,
                'patient_id' => $patient->id, 'facility_id' => $this->facility->id,
            ])->execute();
            (new CommitmentFee([
                'appointment_id' => (int) Yii::$app->db->getLastInsertID(), 'patient_id' => $patient->id, 'consultant_id' => $this->consultant->user_id,
                'facility_id' => $this->facility->id, 'fee_date' => date('Y-m-d'), 'amount' => 250,
                'payer' => CommitmentFee::PAYER_PATIENT, 'state' => $state, 'phone' => '254712345678', 'mpesa_transaction_id' => $txId,
            ]))->save(false);
        }

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/payment/index');
        $I->see('Patient Pay');
        $I->see('UKG8420XHO');
        $I->see('0712***678');
        $I->dontSee('254712345678');
        $I->see('Paid: 1 · Ksh 250', '#totals');

        $I->amOnRoute('/payment/index', ['state' => CommitmentFee::STATE_FORFEITED]);
        $I->dontSee('UKG8420XHO');
        $I->see('Forfeited: 1 · Ksh 250', '#totals');
    }

    public function staffCannotOpenPilots(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->user('front.desk2', 'front_desk'));
        $I->amOnRoute('/pilot/index');
        $I->seeResponseCodeIs(403);
    }
}
