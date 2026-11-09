<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\PermissionForm $model */
/** @var yii\rbac\Permission[] $existingPermissions */

$parentOptions = [];
foreach ($existingPermissions as $name => $permission) {
    if ($name !== $model->originalName) {
        $parentOptions[$name] = $name . ($permission->description ? ' — ' . $permission->description : '');
    }
}
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php $form = ActiveForm::begin(); ?>

        <?= $form->errorSummary($model) ?>

        <?= $form->field($model, 'name')->textInput(['maxlength' => true, 'placeholder' => 'e.g. manageInvoices']) ?>

        <?= $form->field($model, 'description')->textInput(['maxlength' => true]) ?>

        <?= $form->field($model, 'parent')->dropDownList($parentOptions, ['prompt' => '— none —'])
            ->hint('Anyone holding the parent permission also gets this one.') ?>

        <div class="d-flex gap-2">
            <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
            <?= Html::a('Cancel', ['permissions'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?php ActiveForm::end(); ?>
    </div>
</div>
