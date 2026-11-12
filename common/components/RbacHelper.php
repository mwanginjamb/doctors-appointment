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
 *                        they book for is kept in front_desk_assignment, and the facility they work
 *                        at in user.facility_id, not in RBAC
 *  - facility-admin    : default role for users whose `user.role` is "facility_admin"; runs the
 *                        facility in user.facility_id (its board, front desk, closed days, contact
 *                        details and audit log), never another
 */
class RbacHelper
{
    const ROLE_ADMIN = 'consultancy-admin';
    const ROLE_CONSULTANT = 'consultant';
    const ROLE_PATIENT = 'patient';
    const ROLE_FRONT_DESK = 'front-desk';
    const ROLE_FACILITY_ADMIN = 'facility-admin';

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
    const PERM_VIEW_AUDIT_LOG = 'viewAuditLog';

    // The same permissions limited by a rule: "the consultant themselves" or "front desk assigned to them"
    const PERM_BOOK_OWN_CALENDAR = 'bookOwnCalendar';
    const PERM_BOOK_FOR_ASSIGNED_CONSULTANT = 'bookForAssignedConsultant';
    const PERM_HANDLE_ASSIGNED_APPOINTMENT = 'handleAssignedAppointment';
    const PERM_RECORD_OWN_OUTCOME = 'recordOwnOutcome';
    const PERM_RECORD_ASSIGNED_OUTCOME = 'recordAssignedOutcome';
    const PERM_CONFIRM_OWN_APPOINTMENT = 'confirmOwnAppointment';
    const PERM_MANAGE_OWN_AVAILABILITY = 'manageOwnAvailability';
    const PERM_UPDATE_OWN_CONSULTANT_PROFILE = 'updateOwnConsultantProfile';

    // F8 phase 2: front desk's assigned-consultant permissions, limited again to their facility
    const PERM_BOOK_AT_ASSIGNED_FACILITY = 'bookAtAssignedFacility';
    const PERM_HANDLE_AT_ASSIGNED_FACILITY = 'handleAtAssignedFacility';
    const PERM_RECORD_AT_ASSIGNED_FACILITY = 'recordAtAssignedFacility';

    // Facility admins. The general permissions name the facility in their params
    // (['facilityId' => …], ['facility' => …] or ['appointment' => …]); consultancy admins hold them
    // for every facility, facility admins through the facilityAdmin rule for their own.
    const PERM_MANAGE_FRONT_DESK = 'manageFrontDesk';
    const PERM_MANAGE_FACILITY_CLOSURES = 'manageFacilityClosures';
    const PERM_EDIT_FACILITY_CONTACT = 'editFacilityContact';
    const PERM_VIEW_FACILITY_AUDIT_LOG = 'viewFacilityAuditLog';
    const PERM_HANDLE_FACILITY_APPOINTMENT = 'handleFacilityAppointment';
    const PERM_RECORD_FACILITY_OUTCOME = 'recordFacilityOutcome';
    const PERM_MANAGE_OWN_FACILITY_FRONT_DESK = 'manageOwnFacilityFrontDesk';
    const PERM_MANAGE_OWN_FACILITY_CLOSURES = 'manageOwnFacilityClosures';
    const PERM_EDIT_OWN_FACILITY_CONTACT = 'editOwnFacilityContact';
    const PERM_VIEW_OWN_FACILITY_AUDIT_LOG = 'viewOwnFacilityAuditLog';

    // F8 phase 3: one-off sessions. Params: ['consultant' => Consultant, 'facilityId' => …]
    const PERM_MANAGE_SESSIONS = 'manageConsultantSessions';
    const PERM_MANAGE_OWN_SESSIONS = 'manageOwnSessions';
    const PERM_MANAGE_ASSIGNED_SESSIONS = 'manageAssignedSessions';
    const PERM_MANAGE_ASSIGNED_SESSIONS_AT_FACILITY = 'manageAssignedSessionsAtFacility';
    const PERM_MANAGE_FACILITY_SESSIONS = 'manageFacilitySessions';

    /**
     * Roles the application code depends on by name. They cannot be renamed or deleted from the UI.
     */
    const CORE_ROLES = [self::ROLE_ADMIN, self::ROLE_CONSULTANT, self::ROLE_PATIENT, self::ROLE_FRONT_DESK, self::ROLE_FACILITY_ADMIN];

    /**
     * Everything `php yii rbac/init` seeds:
     *  - rules: name => class
     *  - permissions: name => description
     *  - ruledPermissions: name => [description, rule name, the permission it grants]
     *  - roles: name => [description, permissions and ruled permissions]
     *  - detached: role => children it no longer holds directly (removed by rbac/init), because a
     *    ruled permission now stands between them
     */
    public static function definitions()
    {
        return [
            'rules' => [
                'isTheConsultant' => \common\rbac\IsTheConsultantRule::class,
                'assignedConsultant' => \common\rbac\AssignedConsultantRule::class,
                'assignedFacility' => \common\rbac\AssignedFacilityRule::class,
                'facilityAdmin' => \common\rbac\FacilityAdminRule::class,
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
                self::PERM_VIEW_AUDIT_LOG => 'Read the audit log: who changed what, sign-ins',
                self::PERM_MANAGE_FRONT_DESK => "Create and edit a facility's front-desk staff",
                self::PERM_MANAGE_FACILITY_CLOSURES => "Close a facility for a day",
                self::PERM_EDIT_FACILITY_CONTACT => "Edit a facility's address, phone and map link",
                self::PERM_VIEW_FACILITY_AUDIT_LOG => "Read a facility's audit log",
                self::PERM_MANAGE_SESSIONS => "Add and remove a consultant's one-off sessions at a facility",
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
                // Both rules on the way: the assigned consultant, at the front desk's own facility
                self::PERM_BOOK_AT_ASSIGNED_FACILITY => ["Book for assigned consultants at own facility", 'assignedFacility', self::PERM_BOOK_FOR_ASSIGNED_CONSULTANT],
                self::PERM_HANDLE_AT_ASSIGNED_FACILITY => ["Open and cancel assigned consultants' appointments at own facility", 'assignedFacility', self::PERM_HANDLE_ASSIGNED_APPOINTMENT],
                self::PERM_RECORD_AT_ASSIGNED_FACILITY => ["Record outcomes of assigned consultants' appointments at own facility", 'assignedFacility', self::PERM_RECORD_ASSIGNED_OUTCOME],
                // Facility admins: the same, for the facility they run
                self::PERM_HANDLE_FACILITY_APPOINTMENT => ["Open and cancel appointments at own facility", 'facilityAdmin', self::PERM_HANDLE_APPOINTMENT],
                self::PERM_RECORD_FACILITY_OUTCOME => ["Record outcomes at own facility", 'facilityAdmin', self::PERM_RECORD_OUTCOME],
                self::PERM_MANAGE_OWN_FACILITY_FRONT_DESK => ["Manage own facility's front-desk staff", 'facilityAdmin', self::PERM_MANAGE_FRONT_DESK],
                self::PERM_MANAGE_OWN_FACILITY_CLOSURES => ['Close own facility for a day', 'facilityAdmin', self::PERM_MANAGE_FACILITY_CLOSURES],
                self::PERM_EDIT_OWN_FACILITY_CONTACT => ["Edit own facility's contact details", 'facilityAdmin', self::PERM_EDIT_FACILITY_CONTACT],
                self::PERM_VIEW_OWN_FACILITY_AUDIT_LOG => ["Read own facility's audit log", 'facilityAdmin', self::PERM_VIEW_FACILITY_AUDIT_LOG],
                // One-off sessions: the consultant; their front desk at its facility; facility admins at theirs
                self::PERM_MANAGE_OWN_SESSIONS => ['Add and remove own one-off sessions', 'isTheConsultant', self::PERM_MANAGE_SESSIONS],
                self::PERM_MANAGE_ASSIGNED_SESSIONS => ["Add and remove assigned consultants' sessions", 'assignedConsultant', self::PERM_MANAGE_SESSIONS],
                self::PERM_MANAGE_ASSIGNED_SESSIONS_AT_FACILITY => ["Add and remove assigned consultants' sessions at own facility", 'assignedFacility', self::PERM_MANAGE_ASSIGNED_SESSIONS],
                self::PERM_MANAGE_FACILITY_SESSIONS => ["Add and remove consultants' sessions at own facility", 'facilityAdmin', self::PERM_MANAGE_SESSIONS],
            ],
            'roles' => [
                self::ROLE_ADMIN => [
                    'Consultancy administrator',
                    [
                        self::PERM_MANAGE_RBAC, self::PERM_MANAGE_USERS, self::PERM_MANAGE_REFERENCE_DATA,
                        self::PERM_VIEW_CLINIC_BOARD, self::PERM_VIEW_ALL_APPOINTMENTS, self::PERM_HANDLE_APPOINTMENT,
                        self::PERM_RECORD_OUTCOME, self::PERM_MANAGE_AVAILABILITY, self::PERM_UPDATE_CONSULTANT_PROFILE,
                        self::PERM_MANAGE_CONSULTANTS, self::PERM_VIEW_AUDIT_LOG,
                        self::PERM_MANAGE_FRONT_DESK, self::PERM_MANAGE_FACILITY_CLOSURES,
                        self::PERM_EDIT_FACILITY_CONTACT, self::PERM_VIEW_FACILITY_AUDIT_LOG,
                        self::PERM_MANAGE_SESSIONS,
                    ],
                ],
                self::ROLE_FACILITY_ADMIN => [
                    'Facility administrator',
                    [
                        self::PERM_VIEW_CLINIC_BOARD,
                        self::PERM_HANDLE_FACILITY_APPOINTMENT, self::PERM_RECORD_FACILITY_OUTCOME,
                        self::PERM_MANAGE_OWN_FACILITY_FRONT_DESK, self::PERM_MANAGE_OWN_FACILITY_CLOSURES,
                        self::PERM_EDIT_OWN_FACILITY_CONTACT, self::PERM_VIEW_OWN_FACILITY_AUDIT_LOG,
                        self::PERM_MANAGE_FACILITY_SESSIONS,
                    ],
                ],
                self::ROLE_CONSULTANT => [
                    'Consultant offering appointments',
                    [
                        self::PERM_MANAGE_CONSULTANT_PROFILE, self::PERM_MANAGE_APPOINTMENTS,
                        self::PERM_BOOK_OWN_CALENDAR, self::PERM_RECORD_OWN_OUTCOME, self::PERM_CONFIRM_OWN_APPOINTMENT,
                        self::PERM_MANAGE_OWN_AVAILABILITY, self::PERM_UPDATE_OWN_CONSULTANT_PROFILE,
                        self::PERM_LOOK_UP_PATIENTS, self::PERM_SEND_BOOKING_LINKS, self::PERM_MANAGE_OWN_SESSIONS,
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
                        self::PERM_BOOK_AT_ASSIGNED_FACILITY, self::PERM_HANDLE_AT_ASSIGNED_FACILITY,
                        self::PERM_RECORD_AT_ASSIGNED_FACILITY, self::PERM_LOOK_UP_PATIENTS, self::PERM_SEND_BOOKING_LINKS,
                        self::PERM_MANAGE_ASSIGNED_SESSIONS_AT_FACILITY,
                    ],
                ],
            ],
            'detached' => [
                self::ROLE_FRONT_DESK => [
                    self::PERM_BOOK_FOR_ASSIGNED_CONSULTANT, self::PERM_HANDLE_ASSIGNED_APPOINTMENT, self::PERM_RECORD_ASSIGNED_OUTCOME,
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
            case 'facility_admin':
                return self::ROLE_FACILITY_ADMIN;
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
