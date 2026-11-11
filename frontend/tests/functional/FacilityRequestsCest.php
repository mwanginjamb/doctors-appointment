<?php

namespace frontend\tests\functional;

use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FacilityRequest;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

/**
 * F8: a consultant asks for a facility that is not listed; an admin creates it or declines it, and
 * the consultant is told.
 */
class FacilityRequestsCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    private function queued($class, array $contains = [])
    {
        $query = (new Query())->from('{{%queue}}')->where(['like', 'job', $class]);
        foreach ($contains as $text) {
            $query->andWhere(['like', 'job', $text]);
        }

        return (int) $query->count();
    }

    private function request($name = 'Karen Hospital', array $attributes = [])
    {
        $request = new FacilityRequest();
        $request->setAttributes($attributes + ['name' => $name, 'address' => 'Langata Rd', 'note' => 'Thursdays only']);
        $request->consultant_id = $this->consultant->id;
        $request->save(false);

        return $request;
    }

    // ---- The consultant asks -----------------------------------------------------------------

    public function theListOffersToRequestAFacilityThatIsNotListed(FunctionalTester $I)
    {
        (new Facility(['name' => 'Avenue Clinic']))->save(false);
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');

        $I->seeElement('#new-facility option[value="request"]');
        $I->seeElement('#facility-request.d-none', [], 'hidden until "Not listed" is chosen');
    }

    public function withNothingElseListedTheRequestFormIsShown(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');

        $I->see('No other facilities are listed yet.');
        $I->seeElement('#facility-request');
        $I->dontSeeElement('#facility-request.d-none');
    }

    public function consultantRequestsAFacility(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#facility-request-form', [
            'FacilityRequest[name]' => 'Karen Hospital',
            'FacilityRequest[address]' => 'Langata Rd',
            'FacilityRequest[map_url]' => 'maps.example.com/karen',
            'FacilityRequest[note]' => 'Thursdays only',
        ]);

        $I->see('Request for Karen Hospital sent.');
        $I->see('Waiting', '#facility-requests');
        $request = FacilityRequest::findOne(['name' => 'Karen Hospital']);
        $I->assertSame($this->consultant->id, (int) $request->consultant_id);
        $I->assertSame(FacilityRequest::STATUS_PENDING, $request->status);
        $I->assertSame('https://maps.example.com/karen', $request->map_url);
        $I->assertFalse(Facility::find()->where(['name' => 'Karen Hospital'])->exists(), 'not listed until an admin creates it');
    }

    public function aListedFacilityOrARepeatCannotBeRequested(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->submitForm('#facility-request-form', ['FacilityRequest[name]' => 'main clinic']);
        $I->see('Main clinic is already listed: choose it from the list.');

        $this->request();
        $I->submitForm('#facility-request-form', ['FacilityRequest[name]' => 'Karen Hospital']);
        $I->see('You have already asked for Karen Hospital.');
        $I->assertSame(1, (int) FacilityRequest::find()->count());
    }

    // ---- The admin answers -------------------------------------------------------------------

    public function onlyAdminsSeeRequests(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('facility/requests');
        $I->seeResponseCodeIs(403);
    }

    public function adminCreatesTheRequestedFacility(FunctionalTester $I)
    {
        $request = $this->request();
        $before = $this->queued('SendEmailMessageJob', ['Karen Hospital is now listed']);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility/index');
        $I->see('1 facility request is waiting.');
        $I->amOnRoute('facility/requests');
        $I->see('Karen Hospital', '.facility-request');
        $I->see('Thursdays only');
        $I->seeLink('Create');

        $I->amOnRoute('facility/create-from-request', ['id' => $request->id]);
        $I->seeInField('Facility[name]', 'Karen Hospital');
        $I->seeInField('Facility[address]', 'Langata Rd');
        $I->submitForm('#facility-form', ['Facility[name]' => 'The Karen Hospital', 'Facility[phone]' => '0730 000 000']);

        $I->see('The Karen Hospital added, and Dr. Test now works there.');
        $facility = Facility::findOne(['name' => 'The Karen Hospital']);
        $I->assertNotNull($facility);
        $I->assertTrue($facility->isActive());
        $request = FacilityRequest::findOne($request->id);
        $I->assertSame(FacilityRequest::STATUS_CREATED, $request->status);
        $I->assertSame($facility->id, (int) $request->facility_id);
        $I->assertSame($this->admin->id, (int) $request->decided_by);
        $I->assertTrue(ConsultantFacility::find()->where(['consultant_id' => $this->consultant->id, 'facility_id' => $facility->id])->exists());
        $I->assertSame($before + 1, $this->queued('SendEmailMessageJob', ['The Karen Hospital is now listed']));
        $I->assertSame(1, $this->queued('SendWhatsappTextJob', ['The Karen Hospital is now listed']));

        // The consultant sees it among their facilities
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->see('The Karen Hospital', '.engagement-row');
        $I->see('Added', '#facility-requests');
    }

    public function adminDeclinesWithAReason(FunctionalTester $I)
    {
        $request = $this->request();

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility/requests');
        $I->submitForm('.decline-form', ['reason' => 'Already listed as Karen Hosp.']);

        $I->see('Request for Karen Hospital declined. Dr. Test has been told.');
        $request = FacilityRequest::findOne($request->id);
        $I->assertSame(FacilityRequest::STATUS_DECLINED, $request->status);
        $I->assertSame('Already listed as Karen Hosp.', $request->decision_note);
        $I->assertFalse(Facility::find()->where(['name' => 'Karen Hospital'])->exists());
        $I->assertSame(1, $this->queued('SendWhatsappTextJob', ['was declined', 'Already listed as Karen Hosp.']));

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('availability/index');
        $I->see('Declined', '#facility-requests');
        $I->see('Already listed as Karen Hosp.');
    }

    public function anAnsweredRequestCannotBeAnsweredAgain(FunctionalTester $I)
    {
        $request = $this->request();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('facility/requests');
        $I->submitForm('.decline-form', []);

        $I->amOnRoute('facility/create-from-request', ['id' => $request->id]);
        $I->see('That request has already been answered.');
        $I->assertFalse(Facility::find()->where(['name' => 'Karen Hospital'])->exists());
        $I->assertSame(1, $this->queued('SendWhatsappTextJob', ['Karen Hospital']), 'told once');
    }
}
