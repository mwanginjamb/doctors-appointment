<?php

namespace frontend\components;

use Yii;
use yii\filters\AccessControl;
use common\components\RbacHelper;

/**
 * Access filter backed by RBAC. By default only users holding the consultancy-admin role pass;
 * set {@see $permission} to gate on a finer-grained permission instead, e.g.
 *
 *   'access' => ['class' => AdminAccessControl::class, 'permission' => RbacHelper::PERM_MANAGE_REFERENCE_DATA]
 *
 * Grant the admin role with `php yii rbac/assign-admin <username>`.
 */
class AdminAccessControl extends AccessControl
{
    const ROLE_ADMIN = RbacHelper::ROLE_ADMIN;

    /** @var string RBAC role or permission required */
    public $permission = RbacHelper::ROLE_ADMIN;

    public function init()
    {
        $this->rules = [
            [
                'allow' => true,
                'roles' => [$this->permission],
            ],
        ];
        parent::init();
    }

    public static function isAdmin()
    {
        return !Yii::$app->user->isGuest && Yii::$app->user->can(self::ROLE_ADMIN);
    }
}
