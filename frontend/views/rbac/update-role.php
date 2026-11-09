<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\RoleForm $model */
/** @var yii\rbac\Permission[] $permissions */

$this->title = 'Update Role: ' . $model->originalName;
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = ['label' => 'Roles', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->originalName;
?>
<div class="rbac-update-role">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <?= Html::a('Manage child roles / permissions', ['manage-hierarchy', 'name' => $model->originalName], ['class' => 'btn btn-outline-secondary']) ?>
    </div>
    <?= $this->render('_role_form', ['model' => $model, 'permissions' => $permissions]) ?>
</div>
