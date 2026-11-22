<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;
use yii\helpers\Url;

/**
 * F13: the front desk confirms their consultants' appointments (at their facility), saying how
 * the patient confirmed; so do facility admins at theirs and consultancy admins.
 */
class ConfirmAppointmentCest
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;
    /** @var Appointments */
    private $appointment;
    /** @var int */
    private $mainId;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $this->frontDesk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
        $this->appointment = $this->book('10:00');
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
    }

    private function confirmUrl()
    {
        return Url::to(['/appointments/confirm', 'id' => $this->appointment->id]);
    }

    /** POSTs to confirm as the signed-in user, with the CSRF token a page would send. */
    private function postConfirm(FunctionalTester $I, $id, $via)
    {
        $I->amOnRoute('/site/index');
        $param = $I->grabAttributeFrom('meta[name=csrf-param]', 'content');
        $token = $I->grabAttributeFrom('meta[name=csrf-token]', 'content');
        $I->sendAjaxPostRequest(Url::to(['/appointments/confirm', 'id' => $id]), ['via' => $via, $param => $token]);
    }

    private function reload()
    {
        return Appointments::findOne($this->appointment->id);
    }

    public function theFrontDeskConfirmsFromTheBoardSayingHow(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('/board/index', ['from' => $this->date, 'to' => $this->date]);
        $I->seeElement('#confirm-' . $this->appointment->id);
        $I->see('How did the patient confirm?', '#confirm-' . $this->appointment->id);
        $I->see('By phone', '#confirm-' . $this->appointment->id);

        $I->submitForm('#confirm-' . $this->appointment->id . ' form', ['via' => Appointments::CONFIRMED_PHONE]);
        $I->see('Appointment confirmed (by phone). The patient has been sent the confirmation.');
        $I->dontSeeElement('#confirm-' . $this->appointment->id);

        $appointment = $this->reload();
        $I->assertSame(Appointments::STATUS_CONFIRMED, $appointment->status);
        $I->assertSame(Appointments::CONFIRMED_PHONE, $appointment->confirmed_via);
        $I->assertSame((int) $this->frontDesk->id, (int) $appointment->confirmed_by);
        $I->assertNotEmpty($appointment->confirmed_at);
        $I->assertGreaterThan(0, (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'Appointment Confirmation'])->count(), 'the patient is sent the confirmation');

        $I->amOnRoute('/appointments/view', ['id' => $this->appointment->id]);
        $I->see('By phone, by Front Desk', '#appointment-confirmation');
    }

    public function howThePatientConfirmedMustBeGiven(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->frontDesk);
        $this->postConfirm($I, $this->appointment->id, 'carrier-pigeon');
        $I->assertSame(Appointments::STATUS_SCHEDULED, $this->reload()->status);
    }

    public function frontDeskAtAnotherFacilityOrUnassignedCannotConfirm(FunctionalTester $I)
    {
        $other = new Facility(['name' => 'Avenue Clinic', 'active' => true]);
        $other->save(false);
        $elsewhere = $this->createUser('desk.avenue', FrontDesk::ROLE);
        $elsewhere->updateAttributes(['facility_id' => $other->id]);
        FrontDeskAssignment::replaceFor($elsewhere->id, [$this->consultant->id]);
        $I->amLoggedInAs($elsewhere);
        $this->postConfirm($I, $this->appointment->id, Appointments::CONFIRMED_PHONE);
        $I->seeResponseCodeIs(404);

        FrontDesk::reset();
        $unassigned = $this->createUser('desk.none', FrontDesk::ROLE);
        $unassigned->updateAttributes(['facility_id' => $this->mainId]);
        $I->amLoggedInAs($unassigned);
        $this->postConfirm($I, $this->appointment->id, Appointments::CONFIRMED_PHONE);
        $I->seeResponseCodeIs(404);
        $I->assertSame(Appointments::STATUS_SCHEDULED, $this->reload()->status);
    }

    public function thePatientCannotConfirmAsStaff(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $this->postConfirm($I, $this->appointment->id, Appointments::CONFIRMED_PHONE);
        $I->seeResponseCodeIs(403);
        $I->assertSame(Appointments::STATUS_SCHEDULED, $this->reload()->status);
    }

    public function anAppointmentThatHasStartedCannotBeConfirmed(FunctionalTester $I)
    {
        $this->appointment->updateAttributes(['date' => date('Y-m-d', strtotime('-1 day'))]);
        $I->amLoggedInAs($this->frontDesk);
        $this->postConfirm($I, $this->appointment->id, Appointments::CONFIRMED_IN_PERSON);
        $I->assertSame(Appointments::STATUS_SCHEDULED, $this->reload()->status);
    }

    public function theConsultantAndAdminsConfirmToo(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('/appointments/index');
        $I->seeElement('#confirm-' . $this->appointment->id);
        $I->submitForm('#confirm-' . $this->appointment->id . ' form', ['via' => Appointments::CONFIRMED_MESSAGE]);
        $I->assertSame(Appointments::CONFIRMED_MESSAGE, $this->reload()->confirmed_via);

        $second = $this->book('11:00');
        $I->amLoggedInAs($this->admin);
        $this->postConfirm($I, $second->id, Appointments::CONFIRMED_OTHER);
        $I->assertSame(Appointments::STATUS_CONFIRMED, Appointments::findOne($second->id)->status);

        $third = $this->book('11:30');
        $facilityAdmin = $this->createUser('facility.admin', User::ACCOUNT_FACILITY_ADMIN);
        $facilityAdmin->updateAttributes(['facility_id' => $this->mainId]);
        $I->amLoggedInAs($facilityAdmin);
        $this->postConfirm($I, $third->id, Appointments::CONFIRMED_IN_PERSON);
        $I->assertSame(Appointments::STATUS_CONFIRMED, Appointments::findOne($third->id)->status);
    }
}
