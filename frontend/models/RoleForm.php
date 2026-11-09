<?php

namespace frontend\models;

use Yii;
use yii\base\Model;
use common\components\RbacHelper;

/**
 * Create / update an RBAC role.
 */
class RoleForm extends Model
{
    public $role_name;
    public $description;
    public $permissions = [];

    /** @var string|null role name before editing (update scenario) */
    public $originalName;

    public function scenarios()
    {
        return [
            'create' => ['role_name', 'description', 'permissions'],
            'update' => ['role_name', 'description', 'permissions'],
        ];
    }

    public function rules()
    {
        return [
            [['role_name'], 'required'],
            [['role_name'], 'trim'],
            [['role_name'], 'string', 'max' => 64],
            [['role_name'], 'match', 'pattern' => '/^[a-zA-Z0-9_\-.]+$/', 'message' => 'Use letters, numbers, dots, dashes and underscores only.'],
            [['role_name'], 'validateUniqueName'],
            [['role_name'], 'validateCoreRoleName', 'on' => 'update'],
            [['description'], 'string', 'max' => 255],
            [['permissions'], 'filter', 'filter' => function ($value) {
                return is_array($value) ? array_values(array_filter($value)) : [];
            }],
            [['permissions'], 'each', 'rule' => ['in', 'range' => array_keys(Yii::$app->authManager->getPermissions())]],
        ];
    }

    public function validateUniqueName($attribute)
    {
        if ($this->$attribute === $this->originalName) {
            return;
        }
        $auth = Yii::$app->authManager;
        if ($auth->getRole($this->$attribute) || $auth->getPermission($this->$attribute)) {
            $this->addError($attribute, 'A role or permission with this name already exists.');
        }
    }

    public function validateCoreRoleName($attribute)
    {
        if ($this->originalName !== null && $this->$attribute !== $this->originalName && RbacHelper::isCoreRole($this->originalName)) {
            $this->addError($attribute, "'{$this->originalName}' is a core role used by the application and cannot be renamed.");
        }
    }

    public function attributeLabels()
    {
        return [
            'role_name' => 'Role name',
            'description' => 'Description',
            'permissions' => 'Permissions',
        ];
    }
}
