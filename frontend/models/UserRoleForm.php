<?php

namespace frontend\models;

use Yii;
use yii\base\Model;

/**
 * Assigns RBAC roles to a user.
 */
class UserRoleForm extends Model
{
    public $userId;
    public $roles = [];

    public function rules()
    {
        return [
            [['roles'], 'filter', 'filter' => function ($value) {
                return is_array($value) ? array_values(array_filter($value)) : [];
            }],
            [['roles'], 'each', 'rule' => ['in', 'range' => array_keys(Yii::$app->authManager->getRoles())]],
        ];
    }

    public function attributeLabels()
    {
        return ['roles' => 'Roles'];
    }
}
