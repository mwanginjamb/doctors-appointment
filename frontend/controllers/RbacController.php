<?php

namespace frontend\controllers;

use common\components\Audit;
use common\models\AuditLog;
use Yii;
use yii\db\Query;
use yii\rbac\Item;
use yii\web\Controller;
use frontend\models\User;
use frontend\models\RoleForm;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\data\ArrayDataProvider;
use yii\data\ActiveDataProvider;
use frontend\models\UserRoleForm;
use frontend\models\PermissionForm;
use common\components\RbacHelper;
use yii\web\NotFoundHttpException;

/**
 * Web administration of RBAC roles, permissions and user role assignments.
 * Restricted to holders of the manageRbac permission (granted to consultancy-admin).
 */
class RbacController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => [RbacHelper::PERM_MANAGE_RBAC],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete-role' => ['POST'],
                    'delete-permission' => ['POST'],
                ],
            ],
        ];
    }

    // ── Roles ──────────────────────────────────────────────────────────────

    public function actionIndex()
    {
        $auth = Yii::$app->authManager;
        $rows = [];
        foreach ($auth->getRoles() as $name => $role) {
            $rows[] = [
                'name' => $name,
                'description' => $role->description,
                'permissions' => array_keys($auth->getPermissionsByRole($name)),
                'users' => count($auth->getUserIdsByRole($name)),
                'core' => RbacHelper::isCoreRole($name),
            ];
        }

        $dataProvider = new ArrayDataProvider([
            'allModels' => $rows,
            'key' => 'name',
            'sort' => ['attributes' => ['name', 'users']],
            'pagination' => ['pageSize' => 10],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionCreateRole()
    {
        $model = new RoleForm(['scenario' => 'create']);
        $auth = Yii::$app->authManager;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                $role = $auth->createRole($model->role_name);
                $role->description = $model->description;
                $auth->add($role);
                foreach ($model->permissions as $permName) {
                    $auth->addChild($role, $auth->getPermission($permName));
                }
                $transaction->commit();
                Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $role->name, [], 'Role created; permissions: ' . (implode(', ', $model->permissions) ?: 'none'));
                Yii::$app->session->setFlash('success', "Role '{$role->name}' created.");
                return $this->redirect(['index']);
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', 'Error creating role: ' . $e->getMessage());
            }
        }

        return $this->render('create-role', [
            'model' => $model,
            'permissions' => $auth->getPermissions(),
        ]);
    }

    public function actionUpdateRole($name)
    {
        $auth = Yii::$app->authManager;
        $role = $this->findRole($name);

        $model = new RoleForm(['scenario' => 'update']);
        $model->role_name = $role->name;
        $model->originalName = $role->name;
        $model->description = $role->description;
        // Direct permission children only; inherited ones are managed on the child role
        $model->permissions = array_keys(array_filter(
            $auth->getChildren($name),
            function ($child) {
                return $child->type === Item::TYPE_PERMISSION;
            }
        ));

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                // DbManager::update() renames in place and carries children and assignments along
                $role->name = $model->role_name;
                $role->description = $model->description;
                $auth->update($model->originalName, $role);

                // Replace permission children, leaving any child roles untouched
                foreach ($auth->getChildren($role->name) as $child) {
                    if ($child->type === Item::TYPE_PERMISSION) {
                        $auth->removeChild($role, $child);
                    }
                }
                foreach ($model->permissions as $permName) {
                    $auth->addChild($role, $auth->getPermission($permName));
                }

                $transaction->commit();
                Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $name, [], 'Role updated; permissions: ' . (implode(', ', $model->permissions) ?: 'none'));
                Yii::$app->session->setFlash('success', 'Role updated successfully.');
                return $this->redirect(['index']);
            } catch (\Exception $e) {
                $transaction->rollBack();
                $role->name = $model->originalName;
                Yii::$app->session->setFlash('error', 'Error updating role: ' . $e->getMessage());
            }
        }

        return $this->render('update-role', [
            'model' => $model,
            'permissions' => $auth->getPermissions(),
            'isUpdate' => true,
        ]);
    }

    public function actionDeleteRole($name)
    {
        $auth = Yii::$app->authManager;
        $role = $this->findRole($name);

        if (RbacHelper::isCoreRole($name)) {
            Yii::$app->session->setFlash('error', "'$name' is a core role used by the application and cannot be deleted.");
            return $this->redirect(['index']);
        }

        // remove() also deletes the role's hierarchy links and user assignments
        if ($auth->remove($role)) {
            Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $name, [], 'Role deleted');
            Yii::$app->session->setFlash('success', "Role '$name' deleted. Its permission links and user assignments were removed.");
        } else {
            Yii::$app->session->setFlash('error', "Failed to delete role '$name'.");
        }

        return $this->redirect(['index']);
    }

    // ── User role assignment ───────────────────────────────────────────────

    /**
     * List users with their roles
     * @return string
     */
    public function actionUserRoles($q = null, $role = null)
    {
        $auth = Yii::$app->authManager;
        $query = User::find()->orderBy(['id' => SORT_DESC]);
        if ($q !== null && $q !== '') {
            $query->andWhere(['or', ['like', 'username', $q], ['like', 'email', $q], ['like', 'full_name', $q]]);
        }
        if ($role !== null && $role !== '') {
            $query->andWhere(['id' => $auth->getUserIdsByRole($role) ?: [0]]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('assignment/user-roles', [
            'dataProvider' => $dataProvider,
            'rolesByUser' => $this->rolesByUser(array_column($dataProvider->getModels(), 'id')),
            'allRoles' => $auth->getRoles(),
            'q' => $q,
            'role' => $role,
        ]);
    }

    /**
     * Update user roles
     * @param mixed $userId
     * @return string|\yii\web\Response
     */
    public function actionAssignRole($userId)
    {
        $auth = Yii::$app->authManager;
        $user = User::findOne($userId);
        if ($user === null) {
            throw new NotFoundHttpException('User not found.');
        }

        $rolesBefore = array_keys($auth->getRolesByUser($user->id));
        $model = new UserRoleForm();
        $model->userId = $user->id;
        $model->roles = array_keys($auth->getRolesByUser($user->id));

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $isSelf = (int) $user->id === (int) Yii::$app->user->id;
            if ($isSelf && !in_array(RbacHelper::ROLE_ADMIN, $model->roles, true)) {
                Yii::$app->session->setFlash('error', 'You cannot remove the ' . RbacHelper::ROLE_ADMIN . ' role from your own account.');
                return $this->redirect(['assign-role', 'userId' => $user->id]);
            }

            $transaction = Yii::$app->db->beginTransaction();
            try {
                $auth->revokeAll($user->id);
                foreach ($model->roles as $roleName) {
                    $auth->assign($auth->getRole($roleName), $user->id);
                }

                $transaction->commit();
                Audit::log(AuditLog::ACTION_ROLES_CHANGED, 'user', $user->id, ['roles' => [implode(', ', $rolesBefore) ?: null, implode(', ', $model->roles) ?: null]]);
                Yii::$app->session->setFlash('success', "Roles for {$user->username} updated.");
                return $this->redirect(['user-roles']);
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', 'Error updating roles: ' . $e->getMessage());
            }
        }

        return $this->render('assignment/assign-role', [
            'model' => $model,
            'user' => $user,
            'allRoles' => $auth->getRoles(),
            'nativeRole' => RbacHelper::nativeRoleFor($user->role),
        ]);
    }

    // ── Permissions ────────────────────────────────────────────────────────

    public function actionPermissions()
    {
        $auth = Yii::$app->authManager;
        $rows = [];
        foreach ($auth->getPermissions() as $name => $permission) {
            $parents = RbacHelper::getParents($name);
            $roles = $permParents = [];
            foreach ($parents as $parentName => $parent) {
                if ($parent->type === Item::TYPE_ROLE) {
                    $roles[] = $parentName;
                } else {
                    $permParents[] = $parentName;
                }
            }

            $rows[] = [
                'name' => $permission->name,
                'description' => $permission->description,
                'parents' => $permParents,
                'roles' => $roles,
            ];
        }

        $dataProvider = new ArrayDataProvider([
            'allModels' => $rows,
            'key' => 'name',
            'sort' => ['attributes' => ['name']],
            'pagination' => ['pageSize' => 15],
        ]);

        return $this->render('permissions/index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionCreatePermission()
    {
        $model = new PermissionForm(['scenario' => 'create']);
        $auth = Yii::$app->authManager;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                $permission = $auth->createPermission($model->name);
                $permission->description = $model->description;
                $auth->add($permission);

                if ($model->parent) {
                    $auth->addChild($auth->getPermission($model->parent), $permission);
                }

                $transaction->commit();
                Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $permission->name, [], 'Permission created');
                Yii::$app->session->setFlash('success', "Permission '{$permission->name}' created.");
                return $this->redirect(['permissions']);
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', 'Error: ' . $e->getMessage());
            }
        }

        return $this->render('permissions/create', [
            'model' => $model,
            'existingPermissions' => $auth->getPermissions(),
        ]);
    }

    public function actionUpdatePermission($name)
    {
        $auth = Yii::$app->authManager;
        $permission = $auth->getPermission($name);

        if ($permission === null) {
            Yii::$app->session->setFlash('error', "Permission '{$name}' not found.");
            return $this->redirect(['permissions']);
        }

        $currentParents = $this->permissionParents($name);

        $model = new PermissionForm(['scenario' => 'update']);
        $model->name = $permission->name;
        $model->originalName = $permission->name;
        $model->description = $permission->description;
        $model->parent = $currentParents ? array_key_first($currentParents) : null;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                // update() renames in place, keeping role grants, children and parents
                $permission->name = $model->name;
                $permission->description = $model->description;
                $auth->update($model->originalName, $permission);

                // Re-parent among permissions only; grants to roles are left as they are
                foreach ($this->permissionParents($permission->name) as $parent) {
                    $auth->removeChild($parent, $permission);
                }
                if ($model->parent) {
                    $parent = $auth->getPermission($model->parent);
                    if (!$auth->canAddChild($parent, $permission)) {
                        throw new \yii\base\InvalidArgumentException("'{$model->parent}' cannot be a parent of '{$permission->name}' (it would create a loop).");
                    }
                    $auth->addChild($parent, $permission);
                }

                $transaction->commit();
                Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $permission->name, [], 'Permission updated');
                Yii::$app->session->setFlash('success', 'Permission updated.');
                return $this->redirect(['permissions']);
            } catch (\Exception $e) {
                $transaction->rollBack();
                $permission->name = $model->originalName;
                Yii::$app->session->setFlash('error', 'Error: ' . $e->getMessage());
            }
        }

        return $this->render('permissions/update', [
            'model' => $model,
            'existingPermissions' => $auth->getPermissions(),
        ]);
    }

    public function actionDeletePermission($name)
    {
        $auth = Yii::$app->authManager;
        $permission = $auth->getPermission($name);

        if ($permission === null) {
            Yii::$app->session->setFlash('error', 'Permission not found.');
        } elseif (array_key_exists($name, RbacHelper::definitions()['permissions'])) {
            Yii::$app->session->setFlash('error', "'$name' is a core permission checked by the application and cannot be deleted.");
        } else {
            $auth->remove($permission);
            Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $name, [], 'Permission deleted');
            Yii::$app->session->setFlash('success', "Permission '$name' deleted.");
        }

        return $this->redirect(['permissions']);
    }

    // ── Hierarchy ──────────────────────────────────────────────────────────

    /**
     * Choose the direct children (permissions, and for roles also other roles) of a role or permission.
     */
    public function actionManageHierarchy($name)
    {
        $auth = Yii::$app->authManager;
        $item = $auth->getRole($name) ?? $auth->getPermission($name);
        if ($item === null) {
            throw new NotFoundHttpException("Role or permission '$name' not found.");
        }
        $backRoute = $item->type === Item::TYPE_ROLE ? ['index'] : ['permissions'];

        $candidates = $auth->getPermissions();
        if ($item->type === Item::TYPE_ROLE) {
            $candidates = array_merge($auth->getRoles(), $candidates);
        }
        unset($candidates[$name]);

        if (Yii::$app->request->isPost) {
            $selected = (array) Yii::$app->request->post('children', []);
            $transaction = Yii::$app->db->beginTransaction();
            try {
                $auth->removeChildren($item);
                $skipped = [];
                foreach ($selected as $childName) {
                    if (!isset($candidates[$childName])) {
                        continue;
                    }
                    $child = $candidates[$childName];
                    if ($auth->canAddChild($item, $child)) {
                        $auth->addChild($item, $child);
                    } else {
                        $skipped[] = $childName;
                    }
                }
                $transaction->commit();

                Audit::log(AuditLog::ACTION_RBAC_CHANGED, 'rbac', $name, [], 'Children set to: ' . (implode(', ', array_diff($selected, $skipped)) ?: 'none'));
                Yii::$app->session->setFlash('success', "Children of '$name' updated.");
                if ($skipped) {
                    Yii::$app->session->setFlash('warning', 'Skipped (would create a loop): ' . implode(', ', $skipped));
                }
                return $this->redirect($backRoute);
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', 'Error: ' . $e->getMessage());
            }
        }

        return $this->render('manage-hierarchy', [
            'item' => $item,
            'allItems' => $candidates,
            'currentChildren' => $auth->getChildren($name),
            'backRoute' => $backRoute,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Permissions (not roles) that have $name as a direct child, keyed by name.
     * @return \yii\rbac\Permission[]
     */
    private function permissionParents($name)
    {
        return array_filter(RbacHelper::getParents($name), function ($item) {
            return $item->type === Item::TYPE_PERMISSION;
        });
    }

    /**
     * Role names per user id, fetched in one query.
     * @param int[] $userIds
     * @return array<int, string[]>
     */
    private function rolesByUser(array $userIds)
    {
        if (!$userIds) {
            return [];
        }
        $auth = Yii::$app->authManager;
        $rows = (new Query())
            ->select(['a.user_id', 'a.item_name'])
            ->from(['a' => $auth->assignmentTable])
            ->innerJoin(['i' => $auth->itemTable], 'i.name = a.item_name')
            ->where(['a.user_id' => array_map('strval', $userIds), 'i.type' => Item::TYPE_ROLE])
            ->all($auth->db);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['user_id']][] = $row['item_name'];
        }
        return $map;
    }

    /**
     * @return \yii\rbac\Role
     * @throws NotFoundHttpException
     */
    private function findRole($name)
    {
        $role = Yii::$app->authManager->getRole($name);
        if ($role === null) {
            throw new NotFoundHttpException("Role '$name' not found.");
        }
        return $role;
    }
}
