<?php

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\Consultant $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="consultant-form">

    <?php $form = ActiveForm::begin(['id' => 'form-profile']); ?>

    <?= $form->errorSummary($model) ?>

    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'names')->textInput(['maxlength' => true, 'autofocus' => true, 'placeholder' => 'Mr. firstname secondName lastName', 'value' => 'Dr. ' . ucwords(Yii::$app->user->identity->full_name)]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'facility')->textInput(['maxlength' => true]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'speciality')->textInput(['rows' => 6]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'sub_speciality')->textInput(['rows' => 6]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'kmpdc_registration_number')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'physical_address')->textarea(['placeholder' => 'County, Town, Building ', 'rows' => 6]) ?>
        </div>
    </div>
     <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'role')->dropDownList([
                'admin' => 'Facility Admin',
                'consultant' => 'Consultant'
            ],['prompt' => 'Select Role ...']) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'create_user_account')->checkbox()
            ->hint('Instructs the system to create a new user account for this profile and notify the recipient e-mail.') ?>
        </div>
     </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'license_number')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'practice_type')->textInput(['maxlength' => true]) ?>
            <?php $form->field($model, 'license_type')->textInput(['maxlength' => true]) ?>
        </div>
    </div>

    <div class="row" id="signupFields">
        <div class="col-md-6">
            <?= $form->field($model, 'consultant_email')->textInput(['maxlength' => true,'type' => 'email']) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'phoneNumber')->textInput(['maxlength' => true,'type' => 'tel']) ?>
        </div>
       
    </div>

    


    <?php $form->field($model, 'user_id')->textInput() ?>



    <?php $form->field($model, 'created_at')->textInput() ?>

    <?php $form->field($model, 'updated_at')->textInput() ?>

    <?php $form->field($model, 'created_by')->textInput() ?>

    <?php $form->field($model, 'updated_by')->textInput() ?>

    <?php $form->field($model, 'consultant_id')->textInput() ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>