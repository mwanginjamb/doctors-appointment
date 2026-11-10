<?php

namespace frontend\tests\functional;

use common\models\HealthState;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * T2: the public uptime endpoint and System health on the admin dashboard.
 */
class HealthCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        HealthState::deleteAll();
    }

    public function theUptimeEndpointAnswersOkWithoutDetails(FunctionalTester $I)
    {
        $I->amOnRoute('health/index');
        $I->seeResponseCodeIs(200);
        $I->assertSame('ok', $I->grabPageSource());
    }

    public function itAnswersForSignedInPatientsToo(FunctionalTester $I)
    {
        // The consent gate must not redirect an uptime check
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('health/index');
        $I->assertSame('ok', $I->grabPageSource());
    }

    public function adminsSeeSystemHealthOnTheDashboard(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/settings');
        $I->see('No checks have run yet', '#system-health');

        (new HealthState([
            'check_key' => 'whatsapp',
            'failing' => true,
            'message' => 'The WhatsApp session is SCAN_QR_CODE.',
            'since' => time() - 600,
            'checked_at' => time(),
        ]))->save(false);
        (new HealthState(['check_key' => 'queue', 'failing' => false, 'message' => 'Jobs are being picked up.', 'checked_at' => time()]))->save(false);

        $I->amOnRoute('site/settings');
        $I->see('Problem', '#system-health');
        $I->see('The WhatsApp session is SCAN_QR_CODE.', '#system-health');
        $I->see('Jobs are being picked up.', '#system-health');
        $I->dontSee('have not run for over 15 minutes', '#system-health');
    }
}
