<?php

namespace frontend\tests\functional;

use chillerlan\QRCode\QRCode;
use common\models\User;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FrontDeskAssignment;
use frontend\services\BusinessCard;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use Yii;

/**
 * F29: the consultant's business card (/business-card) and the public profile its QR code opens
 * (/dr/<id>-<name>, no sign-in).
 */
class BusinessCardCest
{
    use BookingDataTrait;

    /** @var Facility[] in the order added (engagements list them by name) */
    private $facilities = [];

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->consultant->updateAttributes(['speciality' => 'Cardiology', 'sub_speciality' => 'Interventional cardiology',
            'kmpdc_registration_number' => 'A1234', 'practice_name' => 'Heart Care Associates']);
        ConsultantFacility::deleteAll(['consultant_id' => $this->consultant->id]);
        $this->facilities = [];
        foreach ([['Mercy Clinic', '020 111 222', 'Ngong Road, Nairobi'], ['Upper Hill Hospital', '020 333 444', 'Upper Hill'],
                  ['Karen Health', '020 555 666', 'Karen'], ['Thika Medical', '067 777 888', 'Thika']] as [$name, $phone, $address]) {
            $facility = new Facility(['name' => $name, 'phone' => $phone, 'address' => $address, 'map_url' => 'https://maps.example/' . urlencode($name), 'active' => 1]);
            $facility->save(false);
            (new ConsultantFacility(['consultant_id' => $this->consultant->id, 'facility_id' => $facility->id, 'session_minutes' => 30, 'booking_open' => 1]))->save(false);
            $this->facilities[] = $facility;
        }
        $this->consultant = Consultant::findOne($this->consultant->id);
    }

    public function thePublicProfileOpensWithoutSigningIn(FunctionalTester $I)
    {
        $I->amOnPage($this->consultant->profileUrl());
        $I->seeResponseCodeIs(200);
        $I->see('Dr. Test', 'h1');
        $I->see('Cardiology · Interventional cardiology', '#doctor-speciality');
        $I->see('KMPDC Reg. No. A1234');
        $I->see('Thika Medical', '#doctor-facilities');
        $I->see('067 777 888', '#doctor-facilities');
        $I->seeElement('#doctor-book');
        $I->dontSee('0712000000');
        $I->dontSee('doctor@example.com');

        $I->click('#doctor-book');
        $I->seeInCurrentUrl('login');
    }

    public function anOldOrMissingNamePartGoesToTheProfile(FunctionalTester $I)
    {
        $I->amOnPage('/dr/' . $this->consultant->id . '-someone-else');
        $I->seeCurrentUrlEquals('/dr/' . $this->consultant->id . '-test');
        $I->amOnPage('/dr/' . $this->consultant->id);
        $I->seeCurrentUrlEquals('/dr/' . $this->consultant->id . '-test');
        $I->amOnPage('/dr/999999-nobody');
        $I->seeResponseCodeIs(404);
    }

    public function aDeactivatedConsultantsProfileSaysSo(FunctionalTester $I)
    {
        $this->consultantUser->updateAttributes(['status' => User::STATUS_INACTIVE]);
        $I->amOnPage($this->consultant->profileUrl());
        $I->seeElement('#doctor-not-taking');
        $I->dontSeeElement('#doctor-book');
    }

    public function theConsultantSeesTheirCardWithTheFirstThreeFacilities(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnPage('/business-card');
        $I->see('Dr. Test', '#business-card-a');
        $I->see('Heart Care Associates', '#business-card-a');
        $I->see('KMPDC Reg. No. A1234', '#business-card-a');
        // Engagements are in facility name order: Karen, Mercy, Thika, Upper Hill
        $I->see('Karen Health', '#business-card-a');
        $I->see('Mercy Clinic', '#business-card-a');
        $I->see('Thika Medical', '#business-card-a');
        $I->dontSee('Upper Hill Hospital', '#business-card-a');
        $I->dontSee('0712000000', '#business-card');
        $I->see('Scan to book online', '#business-card-b');
        $I->seeElement('#business-card-qr svg');
        $I->seeElement('#card-facilities-form');
    }

    public function theConsultantChoosesTheFacilitiesOnTheCard(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnPage('/business-card');
        foreach ($this->facilities as $i => $facility) {
            if (in_array($i, [1, 3], true)) {
                $I->checkOption('#card-facility-' . $facility->id);
            } else {
                $I->uncheckOption('#card-facility-' . $facility->id);
            }
        }
        $I->click('Save', '#card-facilities-form');
        $I->see('Your card is updated.');
        $I->see('Thika Medical', '#business-card-a');
        $I->see('Upper Hill Hospital', '#business-card-a');
        $I->dontSee('Mercy Clinic', '#business-card-a');
        $I->see('Mercy Clinic', '#card-facilities-form');
    }

    public function theAssignedFrontDeskPrintsTheCardButCannotChangeIt(FunctionalTester $I)
    {
        $desk = $this->createUser('desk.one', 'front_desk');
        FrontDeskAssignment::replaceFor($desk->id, [$this->consultant->id]);
        $I->amLoggedInAs($desk);
        $I->amOnPage('/business-card');
        $I->see('Business card: Dr. Test', 'h1');
        $I->seeElement('#card-print');
        $I->dontSeeElement('#card-facilities-form');

        $I->amOnPage('/business-card/' . $this->consultant->id . '/print');
        $I->see('Side A, then side B');
        $I->see('Dr. Test', '#business-card-a');
    }

    public function othersCannotOpenTheCard(FunctionalTester $I)
    {
        $desk = $this->createUser('desk.two', 'front_desk');
        $I->amLoggedInAs($desk);
        $I->amOnPage('/business-card/' . $this->consultant->id);
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs($this->client);
        $I->amOnPage('/business-card/' . $this->consultant->id);
        $I->seeResponseCodeIs(403);
        $I->amOnPage('/business-card');
        $I->seeResponseCodeIs(403);
    }

    public function theQrCodeDownloadsAndOpensTheProfile(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnPage('/business-card/' . $this->consultant->id . '/qr.svg');
        $I->seeResponseCodeIs(200);
        $I->assertStringStartsWith('<?xml', $I->grabPageSource());
        $I->assertStringContainsString('<svg', $I->grabPageSource());
        $I->assertSame('attachment; filename="qr-test.svg"', Yii::$app->response->headers->get('Content-Disposition'));
        $I->amOnPage('/business-card/' . $this->consultant->id . '/qr.png');
        $I->assertSame(chr(0x89) . 'PNG', substr($I->grabPageSource(), 0, 4));

        // The code reads back as the public profile's address
        $png = BusinessCard::qr($this->consultant->profileUrl(true), 'png');
        $I->assertSame($this->consultant->profileUrl(true), (string) (new QRCode())->readFromBlob($png));
        $I->assertStringEndsWith('/dr/' . $this->consultant->id . '-test', $this->consultant->profileUrl(true));
    }
}
