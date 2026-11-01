<?php

namespace frontend\components;

use Yii;
use yii\filters\AccessControl;

/**
 * Access filter that only lets users with the admin role through.
 *
 * The admin role is not offered at signup; promote a user by setting
 * `user.role = 'admin'` in the database.
 */
class AdminAccessControl extends AccessControl
{
    const ROLE_ADMIN = 'admin';

    public function init()
    {
        $this->rules = [
            [
                'allow' => true,
                'roles' => ['@'],
                'matchCallback' => function () {
                    return self::isAdmin();
                },
            ],
        ];
        parent::init();
    }

    public static function isAdmin()
    {
        $identity = Yii::$app->user->identity;
        return $identity !== null && $identity->role === self::ROLE_ADMIN;
    }
}
