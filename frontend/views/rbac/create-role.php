<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\RoleForm $model */
/** @var yii\rbac\Permission[] $permissions */

$this->title = 'Create Role';
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = ['label' => 'Roles', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="rbac-create-role">
    <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>
    <?= $this->render('_role_form', ['model' => $model, 'permissions' => $permissions]) ?>
</div>
