<?php

namespace frontend\models;

use Yii;
use yii\base\Model;

/**
 * Create / update an RBAC permission.
 */
class PermissionForm extends Model
{
    public $name;
    public $description;

    /** @var string|null name of the permission this one is nested under */
    public $parent;

    /** @var string|null permission name before editing (update scenario) */
    public $originalName;

    public function scenarios()
    {
        return [
            'create' => ['name', 'description', 'parent'],
            'update' => ['name', 'description', 'parent'],
        ];
    }

    public function rules()
    {
        return [
            [['name'], 'required'],
            [['name'], 'trim'],
            [['name'], 'string', 'max' => 64],
            [['name'], 'match', 'pattern' => '/^[a-zA-Z0-9_\-.\/]+$/', 'message' => 'Use letters, numbers, dots, dashes, slashes and underscores only.'],
            [['name'], 'validateUniqueName'],
            [['description'], 'string', 'max' => 255],
            [['parent'], 'default', 'value' => null],
            [['parent'], 'in', 'range' => array_keys(Yii::$app->authManager->getPermissions())],
            [['parent'], 'compare', 'compareAttribute' => 'originalName', 'operator' => '!==', 'message' => 'A permission cannot be its own parent.', 'skipOnEmpty' => true],
        ];
    }

    public function validateUniqueName($attribute)
    {
        if ($this->$attribute === $this->originalName) {
            return;
        }
        $auth = Yii::$app->authManager;
        if ($auth->getPermission($this->$attribute) || $auth->getRole($this->$attribute)) {
            $this->addError($attribute, 'A role or permission with this name already exists.');
        }
    }

    public function attributeLabels()
    {
        return [
            'name' => 'Permission name',
            'description' => 'Description',
            'parent' => 'Parent permission',
        ];
    }
}
