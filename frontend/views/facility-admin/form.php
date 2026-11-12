<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\FacilityAdminForm $form */
/** @var array $facilities facility id => name */

$editing = $form->getUser() !== null;
$this->title = $editing ? 'Edit ' . ($form->full_name ?: 'facility admin') : 'Add Facility Admin';
$this->params['breadcrumbs'][] = ['label' => 'Facility Admins', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-admin-form" style="max-width: 40rem">

    <h1><?= Html::encode($this->title) ?></h1>

    <?php $f = ActiveForm::begin(['id' => 'facility-admin-form']); ?>

    <?= $f->field($form, 'full_name')->textInput(['maxlength' => 150]) ?>

    <?= $editing
        ? $f->field($form, 'email')->textInput(['disabled' => true])
        : $f->field($form, 'email')->input('email') ?>

    <?= $f->field($form, 'phone')->input('tel', ['maxlength' => 15]) ?>

    <?= $f->field($form, 'password')->passwordInput(['autocomplete' => 'new-password'])
        ->hint($editing ? '' : 'Give it to them in person; they can change it with "forgot password".') ?>

    <?= $f->field($form, 'facility_id')->dropDownList($facilities, ['prompt' => 'Choose a facility'])
        ->hint('They see and manage this facility only.') ?>

    <div class="form-group">
        <?= Html::submitButton('Save', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-link']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
