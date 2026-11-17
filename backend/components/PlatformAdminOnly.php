<?php

namespace backend\components;

use common\components\RbacHelper;
use Yii;
use yii\base\ActionFilter;
use yii\web\ForbiddenHttpException;

/**
 * The backend is for platform admins only (RBAC role consultancy-admin), attached to the whole
 * application in backend/config/main.php. Guests are sent to the sign-in page; anyone else signed
 * in is signed out and refused, so a consultant's or front desk's session never lingers here.
 */
class PlatformAdminOnly extends ActionFilter
{
    /** @var string[] routes open to guests */
    public $public = ['site/login', 'site/error'];

    public function beforeAction($action)
    {
        if (in_array($action->getUniqueId(), $this->public, true)) {
            return true;
        }
        $user = Yii::$app->user;
        if ($user->isGuest) {
            $user->loginRequired();
            return false;
        }
        if (!self::isPlatformAdmin($user->id)) {
            $user->logout();
            throw new ForbiddenHttpException('The admin area is for platform admins only.');
        }

        return true;
    }

    public static function isPlatformAdmin($userId)
    {
        return $userId && Yii::$app->authManager->checkAccess($userId, RbacHelper::ROLE_ADMIN);
    }
}
