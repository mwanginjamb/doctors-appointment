<?php

namespace console\controllers;

use Yii;
use common\models\User;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use common\components\RbacHelper;

/**
 * Sets up RBAC and manages consultancy administrators.
 *
 *   php yii rbac/init                       seed core roles and permissions (safe to re-run)
 *   php yii rbac/sync                       give every user the default role for their user.role
 *   php yii rbac/assign-admin <user>        make a user a consultancy-admin (username, email or id)
 *   php yii rbac/revoke-admin <user>        remove the consultancy-admin role from a user
 *   php yii rbac/list-admins                list consultancy administrators
 */
class RbacController extends Controller
{
    public $defaultAction = 'init';

    /**
     * Creates the core roles and permissions, adding anything missing. Existing items are kept.
     */
    public function actionInit()
    {
        $auth = Yii::$app->authManager;
        $defs = RbacHelper::definitions();

        foreach ($defs['rules'] ?? [] as $name => $class) {
            if ($auth->getRule($name) === null) {
                $auth->add(new $class());
                $this->stdout("Created rule $name\n", Console::FG_GREEN);
            }
        }

        foreach ($defs['permissions'] as $name => $description) {
            if ($auth->getPermission($name) === null) {
                $perm = $auth->createPermission($name);
                $perm->description = $description;
                $auth->add($perm);
                $this->stdout("Created permission $name\n", Console::FG_GREEN);
            }
        }

        // A ruled permission grants its general permission only when its rule passes
        foreach ($defs['ruledPermissions'] ?? [] as $name => [$description, $ruleName, $grants]) {
            $perm = $auth->getPermission($name);
            if ($perm === null) {
                $perm = $auth->createPermission($name);
                $perm->description = $description;
                $perm->ruleName = $ruleName;
                $auth->add($perm);
                $this->stdout("Created permission $name (rule $ruleName)\n", Console::FG_GREEN);
            }
            $general = $auth->getPermission($grants);
            if (!$auth->hasChild($perm, $general)) {
                $auth->addChild($perm, $general);
                $this->stdout("  $name -> $grants\n");
            }
        }

        foreach ($defs['roles'] as $name => [$description, $permissions]) {
            $role = $auth->getRole($name);
            if ($role === null) {
                $role = $auth->createRole($name);
                $role->description = $description;
                $auth->add($role);
                $this->stdout("Created role $name\n", Console::FG_GREEN);
            }
            foreach ($permissions as $permName) {
                $perm = $auth->getPermission($permName);
                if (!$auth->hasChild($role, $perm)) {
                    $auth->addChild($role, $perm);
                    $this->stdout("  $name -> $permName\n");
                }
            }
        }

        $this->stdout("RBAC roles and permissions are in place.\n", Console::BOLD);
        return ExitCode::OK;
    }

    /**
     * Assigns every user the RBAC role that matches their user.role column (admin, consultant, client).
     */
    public function actionSync()
    {
        $assigned = 0;
        foreach (User::find()->select(['id', 'role'])->asArray()->each(200) as $row) {
            if (RbacHelper::syncNativeRole($row['id'], $row['role'])) {
                $assigned++;
            }
        }
        $this->stdout("Assigned default roles to $assigned user(s).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Grants the consultancy-admin role.
     * @param string $user username, email or id
     */
    public function actionAssignAdmin($user)
    {
        $model = $this->findUser($user);
        if ($model === null) {
            return ExitCode::DATAERR;
        }

        $auth = Yii::$app->authManager;
        $role = $auth->getRole(RbacHelper::ROLE_ADMIN);
        if ($role === null) {
            $this->stderr("Role " . RbacHelper::ROLE_ADMIN . " does not exist. Run `php yii rbac/init` first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        if ($auth->getAssignment($role->name, $model->id)) {
            $this->stdout("{$model->username} is already a " . RbacHelper::ROLE_ADMIN . ".\n");
            return ExitCode::OK;
        }

        $auth->assign($role, $model->id);
        $this->stdout("{$model->username} (#{$model->id}) is now a " . RbacHelper::ROLE_ADMIN . ".\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Revokes the consultancy-admin role.
     * @param string $user username, email or id
     */
    public function actionRevokeAdmin($user)
    {
        $model = $this->findUser($user);
        if ($model === null) {
            return ExitCode::DATAERR;
        }

        $auth = Yii::$app->authManager;
        $role = $auth->getRole(RbacHelper::ROLE_ADMIN);
        if ($role === null || !$auth->getAssignment($role->name, $model->id)) {
            $this->stdout("{$model->username} is not a " . RbacHelper::ROLE_ADMIN . ".\n");
            return ExitCode::OK;
        }

        $auth->revoke($role, $model->id);
        $this->stdout("Revoked " . RbacHelper::ROLE_ADMIN . " from {$model->username}.\n", Console::FG_GREEN);
        if ($model->role === 'admin') {
            $this->stdout("Note: user.role is still 'admin', so `rbac/sync` would re-assign it.\n", Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    /**
     * Lists users holding the consultancy-admin role.
     */
    public function actionListAdmins()
    {
        $ids = Yii::$app->authManager->getUserIdsByRole(RbacHelper::ROLE_ADMIN);
        if (!$ids) {
            $this->stdout("No consultancy administrators yet. Use `php yii rbac/assign-admin <user>`.\n");
            return ExitCode::OK;
        }
        foreach (User::find()->where(['id' => $ids])->all() as $user) {
            $this->stdout(sprintf("#%-6d %-30s %s\n", $user->id, $user->username, $user->email));
        }
        return ExitCode::OK;
    }

    /**
     * @param string $identifier username, email or numeric id
     * @return User|null
     */
    private function findUser($identifier)
    {
        $query = User::find()->where(['username' => $identifier])->orWhere(['email' => $identifier]);
        if (ctype_digit((string) $identifier)) {
            $query->orWhere(['id' => (int) $identifier]);
        }
        $users = $query->all();

        if (count($users) !== 1) {
            $this->stderr(
                $users ? "'$identifier' matches more than one user; use the numeric id.\n" : "No user found for '$identifier'.\n",
                Console::FG_RED
            );
            return null;
        }

        return $users[0];
    }
}
