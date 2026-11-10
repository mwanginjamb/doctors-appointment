<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\NewConsultantForm $form */

$this->title = 'Add Consultant';
$this->params['breadcrumbs'][] = ['label' => 'Consultants', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="manage-consultants-create" style="max-width: 40rem">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">Creates the doctor's sign-in account and a basic profile with the default week. You complete the rest of the profile next.</p>

    <?php $f = ActiveForm::begin(['id' => 'new-consultant-form']); ?>

    <?= $f->field($form, 'full_name')->textInput(['maxlength' => 150]) ?>
    <?= $f->field($form, 'email')->input('email') ?>
    <?= $f->field($form, 'phone')->input('tel', ['maxlength' => 15]) ?>
    <?= $f->field($form, 'practice_name')->textInput(['maxlength' => 255]) ?>
    <?= $f->field($form, 'password')->passwordInput(['autocomplete' => 'new-password'])
        ->hint('Give it to them in person; they can change it with "forgot password".') ?>

    <div class="form-group">
        <?= Html::submitButton('Create consultant', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-link']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
