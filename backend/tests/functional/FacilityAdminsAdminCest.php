<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\components\RbacHelper;
use common\models\User;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FacilityAdminClaim;
use frontend\services\FacilityAdmins;
use Yii;

/**
 * Platform admins approve, decline and revoke consultants who run a facility.
 */
class FacilityAdminsAdminCest
{
    private function user($username, $role)
    {
        $user = new User(['username' => $username, 'email' => "$username@example.com", 'full_name' => ucwords(str_replace('.', ' ', $username)),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
        $user->setPassword('password_0');
        $user->generateAuthKey();
        $user->save(false);

        return $user;
    }

    /** A consultant at a facility, with a claim to run it. */
    private function claim($username, $facilityName)
    {
        $doctor = $this->user($username, 'consultant');
        $consultant = new Consultant(['user_id' => $doctor->id, 'names' => 'Dr. ' . ucfirst($username)]);
        $consultant->save(false);
        $facility = new Facility(['name' => $facilityName, 'active' => true]);
        $facility->save(false);
        (new ConsultantFacility(['consultant_id' => $consultant->id, 'facility_id' => $facility->id, 'session_minutes' => 30, 'booking_open' => true]))->save(false);
        FacilityAdmins::reset();

        return FacilityAdmins::claim($doctor, $facility);
    }

    public function anAdminApprovesDeclinesAndRevokes(FunctionalTester $I)
    {
        $admin = $this->user('platform.admin', 'admin');
        $approve = $this->claim('wanjiru', 'Thika Skin Clinic');
        $decline = $this->claim('otieno', 'Kisumu Eye Centre');
        $I->assertInstanceOf(FacilityAdminClaim::class, $approve);

        $I->amLoggedInAs($admin);
        $I->amOnRoute('/facility-admin/index');
        $I->see('Facility admins (2)', 'nav');
        $I->see('Thika Skin Clinic', '#claims-pending');
        $I->see('Kisumu Eye Centre', '#claims-pending');

        $I->submitForm('#claims-pending tr:first-child .claim-approve-form', []);
        $I->see('Wanjiru now runs Thika Skin Clinic.');
        $I->see('Thika Skin Clinic', '#consultant-admins');
        $I->assertNotNull(Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $approve->user_id));

        $I->submitForm('.claim-decline-form', ['reason' => 'Not their clinic']);
        $I->see('Declined; the consultant is told.');
        $I->assertSame(FacilityAdminClaim::STATUS_DECLINED, FacilityAdminClaim::findOne($decline->id)->status);
        $I->see('Not their clinic', '#claims-recent');

        $I->submitForm('.revoke-form', ['reason' => 'Left']);
        $I->see('no longer runs the facility');
        $I->assertNull(Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $approve->user_id));
        $I->assertNull(User::findOne($approve->user_id)->facility_id);
    }
}
