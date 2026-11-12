<?php

namespace frontend\tests\unit;

use common\components\RbacHelper as R;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * Facility admins act on the facility they run, never another; consultancy admins on every one.
 */
class FacilityAdminPermissionsTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var User */
    private $facilityAdmin;
    /** @var int */
    private $mainId;
    /** @var Facility */
    private $avenue;

    protected function _before()
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->avenue = new Facility(['name' => 'Avenue Clinic', 'active' => true]);
        $this->avenue->save(false);
        $this->facilityAdmin = $this->createUser('main.admin', User::ACCOUNT_FACILITY_ADMIN);
        $this->facilityAdmin->updateAttributes(['facility_id' => $this->mainId]);
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
        FrontDesk::reset();
    }

    private function can($user, $permission, array $params = [])
    {
        return Yii::$app->authManager->checkAccess($user->id, $permission, $params);
    }

    public function testTheyGetTheFacilityAdminRole()
    {
        $this->assertNotNull(Yii::$app->authManager->getAssignment(R::ROLE_FACILITY_ADMIN, $this->facilityAdmin->id));
        $this->assertTrue($this->can($this->facilityAdmin, R::PERM_VIEW_CLINIC_BOARD));
        $this->assertFalse($this->can($this->facilityAdmin, R::PERM_VIEW_ALL_APPOINTMENTS));
        $this->assertFalse($this->can($this->facilityAdmin, R::ROLE_ADMIN));
    }

    public function testTheyActOnTheirFacilityOnly()
    {
        $main = Facility::findOne($this->mainId);
        $permissions = [R::PERM_MANAGE_FRONT_DESK, R::PERM_MANAGE_FACILITY_CLOSURES, R::PERM_EDIT_FACILITY_CONTACT, R::PERM_VIEW_FACILITY_AUDIT_LOG];
        foreach ($permissions as $permission) {
            $this->assertTrue($this->can($this->facilityAdmin, $permission, ['facility' => $main]), "$permission at their facility");
            $this->assertFalse($this->can($this->facilityAdmin, $permission, ['facility' => $this->avenue]), "$permission elsewhere");
            $this->assertFalse($this->can($this->facilityAdmin, $permission), "$permission with no facility named");
            $this->assertTrue($this->can($this->admin, $permission, ['facility' => $this->avenue]), "consultancy admins: $permission anywhere");
            $this->assertFalse($this->can($this->consultantUser, $permission, ['facility' => $main]), "consultants: no $permission");
        }
    }

    public function testTheyHandleButDoNotBookAppointmentsThere()
    {
        $atMain = $this->book('09:00');
        $elsewhere = new Appointments(['facility_id' => $this->avenue->id, 'consultant_id' => $this->consultantUser->id]);

        $this->assertTrue($this->can($this->facilityAdmin, R::PERM_HANDLE_APPOINTMENT, ['appointment' => $atMain]));
        $this->assertTrue($this->can($this->facilityAdmin, R::PERM_RECORD_OUTCOME, ['appointment' => $atMain]));
        $this->assertFalse($this->can($this->facilityAdmin, R::PERM_HANDLE_APPOINTMENT, ['appointment' => $elsewhere]));
        $this->assertFalse($this->can($this->facilityAdmin, R::PERM_BOOK_FOR_CONSULTANT, ['appointment' => $atMain]));
    }

    public function testWithNoFacilitySetTheySeeNothing()
    {
        $this->facilityAdmin->updateAttributes(['facility_id' => null]);
        FrontDesk::reset();
        Yii::$app->user->setIdentity($this->facilityAdmin);

        $this->assertSame(0, FrontDesk::facilityId());
        $this->assertFalse($this->can($this->facilityAdmin, R::PERM_HANDLE_APPOINTMENT, ['appointment' => $this->book('09:00')]));
    }

    public function testTheirHistoryIsTheirFacilitys()
    {
        Yii::$app->user->setIdentity($this->facilityAdmin);
        $this->assertNull(FrontDesk::consultantUserIds(), 'every consultant');
        $this->assertSame($this->mainId, FrontDesk::facilityId());
        $this->assertSame(['appointments.facility_id' => $this->mainId], FrontDesk::historyCondition());
    }
}
