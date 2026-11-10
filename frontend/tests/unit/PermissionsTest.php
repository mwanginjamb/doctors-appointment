<?php

namespace frontend\tests\unit;

use common\components\RbacHelper as R;
use common\rbac\AssignedConsultantRule;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;

/**
 * T8: permissions come only from RBAC. Who may do what, for whom.
 */
class PermissionsTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var \common\models\User */
    private $frontDesk;
    /** @var \common\models\User */
    private $otherDoctor;

    protected function _before()
    {
        AssignedConsultantRule::reset();
        $this->createParticipants();
        $this->otherDoctor = $this->createUser('other.doctor', 'consultant');
        (new Consultant(['user_id' => $this->otherDoctor->id, 'names' => 'Dr. Other']))->save(false);
        $this->frontDesk = $this->createUser('front.desk', 'front_desk');
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
    }

    private function can($user, $permission, array $params = [])
    {
        return \Yii::$app->authManager->checkAccess($user->id, $permission, $params);
    }

    public function testBookingInACalendar()
    {
        $mine = ['consultantUserId' => $this->consultantUser->id];
        $theirs = ['consultantUserId' => $this->otherDoctor->id];

        $this->assertTrue($this->can($this->consultantUser, R::PERM_BOOK_FOR_CONSULTANT, $mine), 'own calendar');
        $this->assertFalse($this->can($this->consultantUser, R::PERM_BOOK_FOR_CONSULTANT, $theirs), "another consultant's");
        $this->assertTrue($this->can($this->frontDesk, R::PERM_BOOK_FOR_CONSULTANT, $mine), 'assigned consultant');
        $this->assertFalse($this->can($this->frontDesk, R::PERM_BOOK_FOR_CONSULTANT, $theirs), 'unassigned consultant');
        $this->assertFalse($this->can($this->admin, R::PERM_BOOK_FOR_CONSULTANT, $mine), 'admins do not book');
        $this->assertFalse($this->can($this->client, R::PERM_BOOK_FOR_CONSULTANT, $mine), 'patients book for themselves only');
    }

    public function testHandlingAndOutcomesOfAnAppointment()
    {
        $appointment = $this->book('10:00');
        $params = ['appointment' => $appointment];

        $this->assertTrue($this->can($this->admin, R::PERM_HANDLE_APPOINTMENT, $params));
        $this->assertTrue($this->can($this->frontDesk, R::PERM_HANDLE_APPOINTMENT, $params));
        $this->assertFalse($this->can($this->client, R::PERM_HANDLE_APPOINTMENT, $params));

        $this->assertTrue($this->can($this->consultantUser, R::PERM_RECORD_OUTCOME, $params), 'own appointment');
        $this->assertTrue($this->can($this->frontDesk, R::PERM_RECORD_OUTCOME, $params));
        $this->assertTrue($this->can($this->admin, R::PERM_RECORD_OUTCOME, $params));
        $this->assertFalse($this->can($this->otherDoctor, R::PERM_RECORD_OUTCOME, $params), "another consultant's appointment");
        $this->assertFalse($this->can($this->client, R::PERM_RECORD_OUTCOME, $params), 'never the patient');

        $this->assertTrue($this->can($this->consultantUser, R::PERM_CONFIRM_APPOINTMENT, $params));
        $this->assertFalse($this->can($this->otherDoctor, R::PERM_CONFIRM_APPOINTMENT, $params));
        $this->assertFalse($this->can($this->frontDesk, R::PERM_CONFIRM_APPOINTMENT, $params));
    }

    public function testAvailabilityAndProfiles()
    {
        $mine = ['consultant' => $this->consultant];

        $this->assertTrue($this->can($this->consultantUser, R::PERM_MANAGE_AVAILABILITY, $mine));
        $this->assertFalse($this->can($this->otherDoctor, R::PERM_MANAGE_AVAILABILITY, $mine));
        $this->assertTrue($this->can($this->admin, R::PERM_MANAGE_AVAILABILITY, $mine));
        $this->assertFalse($this->can($this->frontDesk, R::PERM_MANAGE_AVAILABILITY, $mine));

        $this->assertTrue($this->can($this->consultantUser, R::PERM_UPDATE_CONSULTANT_PROFILE, $mine));
        $this->assertFalse($this->can($this->otherDoctor, R::PERM_UPDATE_CONSULTANT_PROFILE, $mine));
        $this->assertTrue($this->can($this->admin, R::PERM_UPDATE_CONSULTANT_PROFILE, $mine));
    }

    public function testStaffAreas()
    {
        foreach ([$this->admin, $this->frontDesk] as $user) {
            $this->assertTrue($this->can($user, R::PERM_VIEW_CLINIC_BOARD));
        }
        foreach ([$this->consultantUser, $this->client] as $user) {
            $this->assertFalse($this->can($user, R::PERM_VIEW_CLINIC_BOARD));
        }
        $this->assertTrue($this->can($this->admin, R::PERM_VIEW_ALL_APPOINTMENTS));
        $this->assertFalse($this->can($this->frontDesk, R::PERM_VIEW_ALL_APPOINTMENTS));

        foreach ([$this->consultantUser, $this->frontDesk] as $user) {
            $this->assertTrue($this->can($user, R::PERM_LOOK_UP_PATIENTS));
            $this->assertTrue($this->can($user, R::PERM_SEND_BOOKING_LINKS));
        }
        $this->assertFalse($this->can($this->client, R::PERM_LOOK_UP_PATIENTS));
        $this->assertFalse($this->can($this->admin, R::PERM_SEND_BOOKING_LINKS));
        $this->assertTrue($this->can($this->admin, R::PERM_MANAGE_CONSULTANTS));
        $this->assertFalse($this->can($this->frontDesk, R::PERM_MANAGE_CONSULTANTS));
    }

    /**
     * Guard: permissions must not be decided by comparing user.role again. Account types are read
     * through isPatientAccount() / isConsultantAccount() / isFrontDeskAccount(); permissions through can().
     */
    public function testNoRoleStringComparisonsInTheApp()
    {
        $root = dirname(__DIR__, 3);
        $allowed = [
            'common/models/AccountTypeTrait.php', // the account-type helpers themselves
        ];
        $patterns = [
            '/->role\s*(===|!==|==|!=)/',
            '/in_array\(\s*[^,]*->role\b/',
            '/=\s*Yii::\$app->user->identity->role\s*;/',
        ];
        $offenders = [];
        foreach (['frontend/controllers', 'frontend/models', 'frontend/views', 'frontend/components', 'frontend/services', 'common/models', 'common/components', 'common/rbac'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($file->getExtension() !== 'php' || in_array($relative, $allowed, true)) {
                    continue;
                }
                foreach (file($file->getPathname()) as $number => $line) {
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $line)) {
                            $offenders[] = "$relative:" . ($number + 1) . ': ' . trim($line);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Use Yii::\$app->user->can() or the account-type helpers:\n" . implode("\n", $offenders));
    }
}
