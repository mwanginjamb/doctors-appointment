<?php

namespace common\components;

use Yii;
use yii\rbac\Item;
use yii\rbac\ManagerInterface;

/**
 * Shared RBAC vocabulary and helpers used by the web RBAC admin, the console
 * `rbac` command and the user model.
 *
 * Core roles:
 *  - consultancy-admin : runs the platform and manages RBAC (assigned via `php yii rbac/assign-admin`)
 *  - consultant        : default role for users whose `user.role` is "consultant"
 *  - patient           : default role for users whose `user.role` is "client"
 *  - front-desk        : default role for users whose `user.role` is "front_desk"; which consultants
 *                        they book for is kept in front_desk_assignment, not in RBAC
 */
class RbacHelper
{
    const ROLE_ADMIN = 'consultancy-admin';
    const ROLE_CONSULTANT = 'consultant';
    const ROLE_PATIENT = 'patient';
    const ROLE_FRONT_DESK = 'front-desk';

    const PERM_MANAGE_RBAC = 'manageRbac';
    const PERM_MANAGE_USERS = 'manageUsers';
    const PERM_MANAGE_REFERENCE_DATA = 'manageReferenceData';
    /** Create one's own consultant profile */
    const PERM_MANAGE_CONSULTANT_PROFILE = 'manageConsultantProfile';
    /** Legacy, not checked in code; kept so existing assignments stay valid */
    const PERM_MANAGE_APPOINTMENTS = 'manageAppointments';
    const PERM_BOOK_APPOINTMENT = 'bookAppointment';
    /** Legacy, not checked in code; kept so existing assignments stay valid */
    const PERM_MANAGE_CLINIC_BOOKINGS = 'manageClinicBookings';

    // What staff may do. Checked with Yii::$app->user->can($permission, $params); the params name the
    // consultant concerned: ['consultantUserId' => …], ['appointment' => …] or ['consultant' => …].
    const PERM_VIEW_CLINIC_BOARD = 'viewClinicBoard';
    const PERM_VIEW_ALL_APPOINTMENTS = 'viewAllAppointments';
    const PERM_BOOK_FOR_CONSULTANT = 'bookForConsultant';
    const PERM_HANDLE_APPOINTMENT = 'handleAppointment';
    const PERM_RECORD_OUTCOME = 'recordOutcome';
    const PERM_CONFIRM_APPOINTMENT = 'confirmAppointment';
    const PERM_LOOK_UP_PATIENTS = 'lookUpPatients';
    const PERM_SEND_BOOKING_LINKS = 'sendBookingLinks';
    const PERM_MANAGE_AVAILABILITY = 'manageAvailability';
    const PERM_UPDATE_CONSULTANT_PROFILE = 'updateConsultantProfile';
    const PERM_MANAGE_CONSULTANTS = 'manageConsultants';

    // The same permissions limited by a rule: "the consultant themselves" or "front desk assigned to them"
    const PERM_BOOK_OWN_CALENDAR = 'bookOwnCalendar';
    const PERM_BOOK_FOR_ASSIGNED_CONSULTANT = 'bookForAssignedConsultant';
    const PERM_HANDLE_ASSIGNED_APPOINTMENT = 'handleAssignedAppointment';
    const PERM_RECORD_OWN_OUTCOME = 'recordOwnOutcome';
    const PERM_RECORD_ASSIGNED_OUTCOME = 'recordAssignedOutcome';
    const PERM_CONFIRM_OWN_APPOINTMENT = 'confirmOwnAppointment';
    const PERM_MANAGE_OWN_AVAILABILITY = 'manageOwnAvailability';
    const PERM_UPDATE_OWN_CONSULTANT_PROFILE = 'updateOwnConsultantProfile';

    /**
     * Roles the application code depends on by name. They cannot be renamed or deleted from the UI.
     */
    const CORE_ROLES = [self::ROLE_ADMIN, self::ROLE_CONSULTANT, self::ROLE_PATIENT, self::ROLE_FRONT_DESK];

    /**
     * Everything `php yii rbac/init` seeds:
     *  - rules: name => class
     *  - permissions: name => description
     *  - ruledPermissions: name => [description, rule name, the permission it grants]
     *  - roles: name => [description, permissions and ruled permissions]
     */
    public static function definitions()
    {
        return [
            'rules' => [
                'isTheConsultant' => \common\rbac\IsTheConsultantRule::class,
                'assignedConsultant' => \common\rbac\AssignedConsultantRule::class,
            ],
            'permissions' => [
                self::PERM_MANAGE_RBAC => 'Manage roles, permissions and user role assignments',
                self::PERM_MANAGE_USERS => 'View and manage system users',
                self::PERM_MANAGE_REFERENCE_DATA => 'Manage genders, specializations, insurance providers and public holidays',
                self::PERM_MANAGE_CONSULTANT_PROFILE => 'Create own consultant profile',
                self::PERM_MANAGE_APPOINTMENTS => 'Legacy: manage appointments booked with the consultant (not checked)',
                self::PERM_BOOK_APPOINTMENT => 'Book and manage own appointments',
                self::PERM_MANAGE_CLINIC_BOOKINGS => 'Legacy: book for assigned consultants (not checked)',
                self::PERM_VIEW_CLINIC_BOARD => 'Open the appointments board',
                self::PERM_VIEW_ALL_APPOINTMENTS => "See every consultant's appointments on the board",
                self::PERM_BOOK_FOR_CONSULTANT => "Book, move and register walk-ins in a consultant's calendar",
                self::PERM_HANDLE_APPOINTMENT => 'Open and cancel an appointment as staff',
                self::PERM_RECORD_OUTCOME => 'Record attended / no-show',
                self::PERM_CONFIRM_APPOINTMENT => 'Confirm an appointment',
                self::PERM_LOOK_UP_PATIENTS => 'Look up and register patients when booking for them',
                self::PERM_SEND_BOOKING_LINKS => 'Send booking links (remote walk-ins)',
                self::PERM_MANAGE_AVAILABILITY => "Edit a consultant's working hours, time off and session length",
                self::PERM_UPDATE_CONSULTANT_PROFILE => "Edit a consultant's profile",
                self::PERM_MANAGE_CONSULTANTS => 'Add consultants and manage front-desk staff',
            ],
            'ruledPermissions' => [
                self::PERM_BOOK_OWN_CALENDAR => ['Book in own calendar', 'isTheConsultant', self::PERM_BOOK_FOR_CONSULTANT],
                self::PERM_BOOK_FOR_ASSIGNED_CONSULTANT => ['Book for assigned consultants', 'assignedConsultant', self::PERM_BOOK_FOR_CONSULTANT],
                self::PERM_HANDLE_ASSIGNED_APPOINTMENT => ["Open and cancel assigned consultants' appointments", 'assignedConsultant', self::PERM_HANDLE_APPOINTMENT],
                self::PERM_RECORD_OWN_OUTCOME => ['Record outcomes of own appointments', 'isTheConsultant', self::PERM_RECORD_OUTCOME],
                self::PERM_RECORD_ASSIGNED_OUTCOME => ["Record outcomes of assigned consultants' appointments", 'assignedConsultant', self::PERM_RECORD_OUTCOME],
                self::PERM_CONFIRM_OWN_APPOINTMENT => ['Confirm own appointments', 'isTheConsultant', self::PERM_CONFIRM_APPOINTMENT],
                self::PERM_MANAGE_OWN_AVAILABILITY => ['Edit own availability', 'isTheConsultant', self::PERM_MANAGE_AVAILABILITY],
                self::PERM_UPDATE_OWN_CONSULTANT_PROFILE => ['Edit own consultant profile', 'isTheConsultant', self::PERM_UPDATE_CONSULTANT_PROFILE],
            ],
            'roles' => [
                self::ROLE_ADMIN => [
                    'Consultancy administrator',
                    [
                        self::PERM_MANAGE_RBAC, self::PERM_MANAGE_USERS, self::PERM_MANAGE_REFERENCE_DATA,
                        self::PERM_VIEW_CLINIC_BOARD, self::PERM_VIEW_ALL_APPOINTMENTS, self::PERM_HANDLE_APPOINTMENT,
                        self::PERM_RECORD_OUTCOME, self::PERM_MANAGE_AVAILABILITY, self::PERM_UPDATE_CONSULTANT_PROFILE,
                        self::PERM_MANAGE_CONSULTANTS,
                    ],
                ],
                self::ROLE_CONSULTANT => [
                    'Consultant offering appointments',
                    [
                        self::PERM_MANAGE_CONSULTANT_PROFILE, self::PERM_MANAGE_APPOINTMENTS,
                        self::PERM_BOOK_OWN_CALENDAR, self::PERM_RECORD_OWN_OUTCOME, self::PERM_CONFIRM_OWN_APPOINTMENT,
                        self::PERM_MANAGE_OWN_AVAILABILITY, self::PERM_UPDATE_OWN_CONSULTANT_PROFILE,
                        self::PERM_LOOK_UP_PATIENTS, self::PERM_SEND_BOOKING_LINKS,
                    ],
                ],
                self::ROLE_PATIENT => [
                    'Patient booking appointments',
                    [self::PERM_BOOK_APPOINTMENT],
                ],
                self::ROLE_FRONT_DESK => [
                    'Front desk booking for assigned consultants',
                    [
                        self::PERM_MANAGE_CLINIC_BOOKINGS, self::PERM_VIEW_CLINIC_BOARD,
                        self::PERM_BOOK_FOR_ASSIGNED_CONSULTANT, self::PERM_HANDLE_ASSIGNED_APPOINTMENT,
                        self::PERM_RECORD_ASSIGNED_OUTCOME, self::PERM_LOOK_UP_PATIENTS, self::PERM_SEND_BOOKING_LINKS,
                    ],
                ],
            ],
        ];
    }

    /**
     * Maps the `user.role` column to the RBAC role a user gets by default.
     *
     * @param string|null $userRole
     * @return string
     */
    public static function nativeRoleFor($userRole)
    {
        switch ($userRole) {
            case 'admin':
                return self::ROLE_ADMIN;
            case 'consultant':
                return self::ROLE_CONSULTANT;
            case 'front_desk':
                return self::ROLE_FRONT_DESK;
            default: // "client" and legacy/empty values
                return self::ROLE_PATIENT;
        }
    }

    /**
     * Gives a user the RBAC role that matches their `user.role` column. When the column changed,
     * the role that matched the old value is revoked. Roles granted by an administrator are kept.
     *
     * @param int $userId
     * @param string|null $userRole current `user.role`
     * @param string|null $oldUserRole previous `user.role`, when it changed
     * @return bool whether a role was assigned (false when it was already assigned or does not exist yet)
     */
    public static function syncNativeRole($userId, $userRole, $oldUserRole = null)
    {
        $auth = self::auth();
        $target = self::nativeRoleFor($userRole);

        if ($oldUserRole !== null) {
            $previous = self::nativeRoleFor($oldUserRole);
            // Never strip the admin role through a profile edit; that is done with rbac/revoke-admin
            if ($previous !== $target && $previous !== self::ROLE_ADMIN && ($old = $auth->getRole($previous))) {
                $auth->revoke($old, $userId);
            }
        }

        $role = $auth->getRole($target);
        if ($role === null) {
            Yii::warning("RBAC role '$target' does not exist; run `php yii rbac/init`.", __METHOD__);
            return false;
        }
        if ($auth->getAssignment($target, $userId) !== null) {
            return false;
        }
        $auth->assign($role, $userId);
        return true;
    }

    public static function isCoreRole($name)
    {
        return in_array($name, self::CORE_ROLES, true);
    }

    /**
     * Roles and permissions that have $childName as a direct child, keyed by name.
     *
     * @param string $childName
     * @return Item[]
     */
    public static function getParents($childName)
    {
        $auth = self::auth();
        $parents = [];
        foreach (array_merge($auth->getRoles(), $auth->getPermissions()) as $name => $item) {
            if (isset($auth->getChildren($name)[$childName])) {
                $parents[$name] = $item;
            }
        }

        return $parents;
    }

    /**
     * @return ManagerInterface|\yii\rbac\DbManager
     */
    public static function auth()
    {
        return Yii::$app->authManager;
    }
}
