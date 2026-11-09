<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\RoleForm $model */
/** @var yii\rbac\Permission[] $permissions */

$isCore = $model->originalName !== null && \common\components\RbacHelper::isCoreRole($model->originalName);
$permissionItems = [];
foreach ($permissions as $name => $permission) {
    $permissionItems[$name] = Html::encode($name)
        . ($permission->description ? ' <small class="text-muted">— ' . Html::encode($permission->description) . '</small>' : '');
}
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php $form = ActiveForm::begin(); ?>

        <?= $form->errorSummary($model) ?>

        <?= $form->field($model, 'role_name')->textInput([
            'maxlength' => true,
            'readonly' => $isCore,
        ])->hint($isCore ? 'Core roles cannot be renamed.' : null) ?>

        <?= $form->field($model, 'description')->textInput(['maxlength' => true]) ?>

        <?php if ($permissionItems): ?>
            <?= $form->field($model, 'permissions')->checkboxList($permissionItems, ['encode' => false]) ?>
        <?php else: ?>
            <p class="text-muted">No permissions exist yet. <?= Html::a('Create one', ['create-permission']) ?>.</p>
        <?php endif; ?>

        <div class="d-flex gap-2">
            <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
            <?= Html::a('Cancel', ['index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?php ActiveForm::end(); ?>
    </div>
</div>
