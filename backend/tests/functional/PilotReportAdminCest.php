<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use Yii;

/**
 * F26 in the admin area: the pilot report for each facility, its CSV.
 */
class PilotReportAdminCest
{
    /** @var User */
    private $admin;
    /** @var int */
    private $facilityId;

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
        $doctor = $this->user('dr.pilot', 'consultant');
        $start = strtotime('-65 days 09:00');
        $consultant = new Consultant(['user_id' => $doctor->id, 'names' => 'Dr. Pilot', 'first_booking_at' => $start]);
        $consultant->save(false);
        $engagement = ConsultantFacility::findOne(['consultant_id' => $consultant->id]);
        $this->facilityId = (int) $engagement->facility_id;
        foreach ([[2, Appointments::STATUS_NO_SHOW], [3, Appointments::STATUS_COMPLETED], [35, Appointments::STATUS_COMPLETED], [36, Appointments::STATUS_COMPLETED]] as [$day, $status]) {
            Yii::$app->db->createCommand()->insert(Appointments::tableName(), [
                'date' => date('Y-m-d', $start + ($day - 1) * 86400), 'time' => '10:00:00', 'patient_id' => $this->admin->id,
                'consultant_id' => $doctor->id, 'facility_id' => $this->facilityId, 'status' => $status, 'fee' => 1500,
                'booking_channel' => Appointments::CHANNEL_STAFF, 'created_at' => time(),
            ])->execute();
        }
    }

    public function anAdminReadsTheReport(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/index');
        $I->click('Pilot report');
        $I->see('Pilot report', 'h1');
        $I->amOnRoute('/pilot/report', ['facility' => $this->facilityId]);
        $I->see('Dr. Pilot');
        // 50% in month 1, 0% after: (0.5 - 0) x 2 visits = 1 visit kept x Ksh 1,500
        $I->see('50.0% → 0.0%', '#pilot-rate-change');
        $I->see('Ksh 1,500', '#pilot-roi-total');
    }

    public function theCsvDownloads(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('/pilot/report', ['facility' => $this->facilityId, 'format' => 'csv']);
        $I->seeResponseCodeIs(200);
        $I->assertStringContainsString('"Dr. Pilot"', $I->grabPageSource());
    }
}
