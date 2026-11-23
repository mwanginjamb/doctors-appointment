<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use Yii;

/**
 * F26: the pilot report on the main site: a facility admin sees their facility, a consultant
 * their own figures only, and nobody else.
 */
class PilotReportCest
{
    use BookingDataTrait;

    /** @var int */
    private $facilityId;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $start = strtotime('-40 days 09:00');
        Consultant::updateAll(['first_booking_at' => $start], ['id' => $this->consultant->id]);
        $this->facilityId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        foreach ([[3, Appointments::STATUS_NO_SHOW], [4, Appointments::STATUS_COMPLETED], [33, Appointments::STATUS_COMPLETED]] as [$day, $status]) {
            Yii::$app->db->createCommand()->insert(Appointments::tableName(), [
                'date' => date('Y-m-d', $start + ($day - 1) * 86400), 'time' => '10:00:00', 'patient_id' => $this->client->id,
                'consultant_id' => $this->consultantUser->id, 'facility_id' => $this->facilityId, 'status' => $status,
                'booking_channel' => Appointments::CHANNEL_STAFF, 'created_at' => time(),
            ])->execute();
        }
        // Another consultant at the same facility, whose figures Dr. Test must not see
        $other = $this->createUser('other.doctor', 'consultant');
        $second = new Consultant(['user_id' => $other->id, 'names' => 'Dr. Second', 'first_booking_at' => $start]);
        $second->save(false);
        if (!ConsultantFacility::find()->where(['consultant_id' => $second->id, 'facility_id' => $this->facilityId])->exists()) {
            (new ConsultantFacility(['consultant_id' => $second->id, 'facility_id' => $this->facilityId, 'session_minutes' => 30, 'booking_open' => 1]))->save(false);
        }
    }

    public function aFacilityAdminSeesTheirFacility(FunctionalTester $I)
    {
        $admin = $this->createUser('facility.admin', User::ACCOUNT_FACILITY_ADMIN);
        $admin->updateAttributes(['facility_id' => $this->facilityId]);
        $I->amLoggedInAs($admin);
        $I->amOnRoute('/pilot-report/index');
        $I->seeElement('#pilot-report-' . $this->facilityId);
        $I->seeElement('#pilot-roi');
        $I->see('Dr. Test');
        $I->see('Dr. Second');
        $I->see('50.0%', '#pilot-total-' . $this->facilityId . ' .row-noShowRate');
        $I->see('so far (day 41)');
    }

    public function aConsultantSeesOnlyTheirOwnFigures(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('/pilot-report/index');
        $I->see('Dr. Test', '.pilot-report');
        $I->dontSee('Dr. Second');
        $I->dontSeeElement('#pilot-roi');
        $I->seeElement('#pilot-consultant-' . $this->consultant->id);
    }

    public function theCsvDownloads(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('/pilot-report/index', ['facility' => $this->facilityId, 'format' => 'csv']);
        $I->seeResponseCodeIs(200);
        $I->assertStringContainsString('Facility,Consultant,"Pilot start"', $I->grabPageSource());
        $I->assertStringContainsString('"Dr. Test"', $I->grabPageSource());
        $I->assertStringNotContainsString('Dr. Second', $I->grabPageSource());
    }

    public function patientsAndFrontDeskCannotOpenIt(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('/pilot-report/index');
        $I->seeResponseCodeIs(403);
        $desk = $this->createUser('front.desk', 'front_desk');
        $I->amLoggedInAs($desk);
        $I->amOnRoute('/pilot-report/index');
        $I->seeResponseCodeIs(403);
    }
}
