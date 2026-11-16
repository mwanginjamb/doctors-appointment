<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\models\Consultant;
use frontend\models\FacilityAgreement;
use frontend\services\Pilot;
use frontend\services\StandardTerms;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * The terms page, its confirm and approve buttons, and the warnings on every page.
 */
class StandardTermsCest
{
    use BookingDataTrait;

    /** @var int */
    private $facilityId;
    /** @var User */
    private $frontDesk;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->facilityId = (int) $this->consultant->engagements[0]->facility_id;
        $this->frontDesk = $this->createUser('front.desk', User::ACCOUNT_FRONT_DESK);
        $this->frontDesk->updateAttributes(['facility_id' => $this->facilityId]);
        (new FacilityAgreement(['facility_id' => $this->facilityId, 'version' => '1.2', 'signed_on' => '2026-08-01',
            'facility_signatory' => 'Jane Admin, Director', 'platform_signatory' => 'Gumzo HealthFirst']))->save(false);
        // Day 95 of the pilot: in the grace period
        Consultant::updateAll(['first_booking_at' => strtotime('-94 days 10:00')], ['id' => $this->consultant->id]);
    }

    public function theConsultantSeesTheWarningAndTheTermsButCannotConfirm(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/index');
        $I->see('Please confirm the standard terms', '.standard-terms-warning');

        $I->click('Review the terms', '.standard-terms-warning');
        $I->see('Plan A: commitment fee');
        $I->see('Ksh 100 is charged');
        $I->dontSeeElement('#confirm-terms-form');

        $I->postAsLink(['/terms/confirm', 'facility' => $this->facilityId]);
        $I->see("Only the facility's front desk or facility admin can confirm its terms.");
        $I->assertSame(Pilot::TERMS_GRACE, Pilot::termsStatus($this->facilityId));
    }

    public function theFrontDeskConfirmsAndTheWarningGoes(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('terms/view', ['facility' => $this->facilityId]);
        $I->seeElement('.standard-terms-warning');
        $I->submitForm('#confirm-terms-form', []);

        $I->see('Standard terms are confirmed');
        $I->seeElement('#terms-confirmed');
        $I->dontSeeElement('.standard-terms-warning');
        $I->dontSeeElement('#approve-terms-form');
        $I->see('Waiting for the platform to approve.');
        $I->assertSame($this->frontDesk->id, (int) FacilityAgreement::currentFor($this->facilityId)->standard_terms_confirmed_by);

        // The front desk cannot approve their own confirmation
        $I->postAsLink(['/terms/approve', 'facility' => $this->facilityId]);
        $I->see('Only a platform admin can approve standard terms.');
        $I->assertSame(Pilot::TERMS_AWAITING_APPROVAL, Pilot::termsStatus($this->facilityId));
    }

    public function aPlatformAdminIsWarnedAndApproves(FunctionalTester $I)
    {
        StandardTerms::confirm($this->facilityId, $this->frontDesk->id);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('appointments/index');
        $I->see('waiting for your approval', '.standard-terms-warning');
        $I->click('Review the terms', '.standard-terms-warning');
        $I->submitForm('#approve-terms-form', []);

        $I->see('Standard terms are approved');
        $I->seeElement('#terms-approved');
        $I->dontSeeElement('.standard-terms-warning');
        $I->assertSame(Pilot::TERMS_APPROVED, Pilot::termsStatus($this->facilityId));
    }

    public function patientsSeeNeitherTheWarningNorThePage(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/index');
        $I->dontSeeElement('.standard-terms-warning');

        $I->amOnRoute('terms/view', ['facility' => $this->facilityId]);
        $I->seeResponseCodeIs(404);
    }
}
