<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\models\User;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;

/**
 * F29 in the admin area: a consultant's business card, its QR code and the facilities on it.
 */
class BusinessCardAdminCest
{
    /** @var User */
    private $admin;
    /** @var Consultant */
    private $consultant;
    /** @var Facility[] */
    private $facilities = [];

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
        $doctor = $this->user('dr.card', 'consultant');
        $this->consultant = new Consultant(['user_id' => $doctor->id, 'names' => 'Jane Card', 'speciality' => 'Paediatrics',
            'kmpdc_registration_number' => 'B777', 'consultant_phone_number' => '0712000020']);
        $this->consultant->save(false);
        $this->facilities = [];
        foreach (['Alpha Clinic', 'Beta Hospital'] as $name) {
            $facility = new Facility(['name' => $name, 'phone' => '020 000 000', 'active' => 1]);
            $facility->save(false);
            (new ConsultantFacility(['consultant_id' => $this->consultant->id, 'facility_id' => $facility->id, 'session_minutes' => 30, 'booking_open' => 1]))->save(false);
            $this->facilities[] = $facility;
        }
    }

    public function anAdminOpensTheCardFromTheConsultantsList(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/consultants');
        $I->click('Card', '#consultant-' . $this->consultant->id);
        $I->see('Business card: Dr. Jane Card', 'h1');
        $I->see('Paediatrics', '#business-card-a');
        $I->see('KMPDC Reg. No. B777', '#business-card-a');
        $I->see('Alpha Clinic', '#business-card-a');
        $I->dontSee('0712000020');
        $I->seeElement('#business-card-qr svg');
        $I->see('/dr/' . $this->consultant->id . '-jane-card', '#card-profile-link');
    }

    public function anAdminChoosesTheFacilitiesAndDownloadsTheQr(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/business-card/view', ['id' => $this->consultant->id]);
        $I->uncheckOption('#card-facility-' . $this->facilities[0]->id);
        $I->click('Save', '#card-facilities-form');
        $I->see("Jane Card's card is updated.");
        $I->dontSee('Alpha Clinic', '#business-card-a');
        $I->see('Beta Hospital', '#business-card-a');

        $I->amOnRoute('/business-card/qr', ['id' => $this->consultant->id, 'format' => 'svg']);
        $I->assertStringContainsString('<svg', $I->grabPageSource());
        $I->amOnRoute('/business-card/print', ['id' => $this->consultant->id]);
        $I->see('Side A, then side B');
    }
}
