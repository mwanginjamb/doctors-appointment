<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\PermissionForm $model */
/** @var yii\rbac\Permission[] $existingPermissions */

$this->title = 'Create Permission';
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = ['label' => 'Permissions', 'url' => ['permissions']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="rbac-create-permission">
    <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>
    <?= $this->render('_form', ['model' => $model, 'existingPermissions' => $existingPermissions]) ?>
</div>
