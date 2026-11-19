<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityInvitation;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F28: a facility admin invites consultants to their facility and adds new consultant accounts
 * there. Dr. Test works at Main clinic (the facility admin's); Dr. Other works at Westlands Clinic.
 */
class FacilityConsultantsCest
{
    use BookingDataTrait;

    /** @var User */
    private $facilityAdmin;
    /** @var int */
    private $mainId;
    /** @var Consultant */
    private $other;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;

        (new Facility(['name' => 'Westlands Clinic', 'active' => true]))->save(false);
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $this->other = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other', 'facility' => 'Westlands Clinic',
            'consultant_email' => 'other@example.com', 'consultant_phone_number' => '0712000001']);
        $this->other->save(false);

        $this->facilityAdmin = $this->createUser('main.admin', User::ACCOUNT_FACILITY_ADMIN);
        $this->facilityAdmin->updateAttributes(['facility_id' => $this->mainId]);
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
    }

    public function aFacilityAdminSeesTheirConsultants(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('/facility-consultants/index');
        $I->seeResponseCodeIs(200);
        $I->see('Dr. Test', '#consultants-here');
        $I->dontSee('Dr. Other', '#consultants-here');
        $I->see('Dr. Other', '#invite-form option');
        $I->see('Consultants', '.nav-link');
    }

    public function aFacilityAdminInvitesAConsultant(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('/facility-consultants/index');
        $I->submitForm('#invite-form', ['consultant_id' => $this->other->id]);

        $I->see('Dr. Other is invited to work at');
        $I->see('Dr. Other', '#invited');
        $I->assertTrue(FacilityInvitation::find()->where([
            'facility_id' => $this->mainId, 'consultant_id' => $this->other->id, 'status' => FacilityInvitation::STATUS_PENDING,
        ])->exists());
        $I->dontSeeElement('#invite-form option[value="' . $this->other->id . '"]');
    }

    public function aFacilityAdminAddsANewConsultantWhoStartsThere(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->facilityAdmin);
        $I->amOnRoute('/facility-consultants/create');
        $I->submitForm('#new-consultant-form', [
            'NewConsultantForm[full_name]' => 'Dr. New Arrival',
            'NewConsultantForm[email]' => 'new.arrival@example.com',
            'NewConsultantForm[phone]' => '0712000077',
            'NewConsultantForm[password]' => 'password_new1',
        ]);

        $I->see('Dr. New Arrival added at');
        $I->see('Dr. New Arrival', '#consultants-here');
        $user = User::findOne(['email' => 'new.arrival@example.com']);
        $I->assertSame('consultant', $user->role);
        $consultant = Consultant::findOne(['user_id' => $user->id]);
        $engagements = ConsultantFacility::find()->where(['consultant_id' => $consultant->id])->select('facility_id')->column();
        $I->assertSame([$this->mainId], array_map('intval', $engagements));
        $hours = ConsultantWorkingHours::find()->where(['consultant_id' => $consultant->id])->select('facility_id')->distinct()->column();
        $I->assertSame([$this->mainId], array_map('intval', $hours), 'the default week is at the facility');
    }

    public function frontDeskCannotManageConsultants(FunctionalTester $I)
    {
        $frontDesk = $this->createUser('desk.one', FrontDesk::ROLE);
        $frontDesk->updateAttributes(['facility_id' => $this->mainId]);
        $I->amLoggedInAs($frontDesk);
        $I->amOnRoute('/facility-consultants/index');
        $I->seeResponseCodeIs(403);
    }

    public function aConsultancyAdminNamesTheFacility(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/facility-consultants/index', ['facility' => $this->mainId]);
        $I->seeResponseCodeIs(200);
        $I->see('Dr. Test', '#consultants-here');

        $I->amOnRoute('/facility-consultants/index');
        $I->seeResponseCodeIs(403);
    }
}
