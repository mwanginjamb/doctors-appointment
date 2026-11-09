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
 */
class RbacHelper
{
    const ROLE_ADMIN = 'consultancy-admin';
    const ROLE_CONSULTANT = 'consultant';
    const ROLE_PATIENT = 'patient';

    const PERM_MANAGE_RBAC = 'manageRbac';
    const PERM_MANAGE_USERS = 'manageUsers';
    const PERM_MANAGE_REFERENCE_DATA = 'manageReferenceData';
    const PERM_MANAGE_CONSULTANT_PROFILE = 'manageConsultantProfile';
    const PERM_MANAGE_APPOINTMENTS = 'manageAppointments';
    const PERM_BOOK_APPOINTMENT = 'bookAppointment';

    /**
     * Roles the application code depends on by name. They cannot be renamed or deleted from the UI.
     */
    const CORE_ROLES = [self::ROLE_ADMIN, self::ROLE_CONSULTANT, self::ROLE_PATIENT];

    /**
     * Role => [description, permissions] seeded by `php yii rbac/init`.
     */
    public static function definitions()
    {
        return [
            'permissions' => [
                self::PERM_MANAGE_RBAC => 'Manage roles, permissions and user role assignments',
                self::PERM_MANAGE_USERS => 'View and manage system users',
                self::PERM_MANAGE_REFERENCE_DATA => 'Manage genders, specializations and insurance providers',
                self::PERM_MANAGE_CONSULTANT_PROFILE => 'Create and update own consultant profile',
                self::PERM_MANAGE_APPOINTMENTS => 'Manage appointments booked with the consultant',
                self::PERM_BOOK_APPOINTMENT => 'Book and manage own appointments',
            ],
            'roles' => [
                self::ROLE_ADMIN => [
                    'Consultancy administrator',
                    [self::PERM_MANAGE_RBAC, self::PERM_MANAGE_USERS, self::PERM_MANAGE_REFERENCE_DATA],
                ],
                self::ROLE_CONSULTANT => [
                    'Consultant offering appointments',
                    [self::PERM_MANAGE_CONSULTANT_PROFILE, self::PERM_MANAGE_APPOINTMENTS],
                ],
                self::ROLE_PATIENT => [
                    'Patient booking appointments',
                    [self::PERM_BOOK_APPOINTMENT],
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
