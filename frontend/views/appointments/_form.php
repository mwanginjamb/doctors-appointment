<?php

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var app\models\Appointments $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="appointments-form my-3">

    <?php $form = ActiveForm::begin(); ?>

    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'date')->textInput(['readonly' => true, 'disabled' => true]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'time')->textInput(['readonly' => true, 'disabled' => true]) ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'service_id')->textInput() ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'provider_id')->textInput() ?>
        </div>
    </div>

    <?php $form->field($model, 'patient_id')->textInput() ?>
    <?php $form->field($model, 'speciality_id')->textInput() ?>





    <?php $form->field($model, 'location')->textarea(['rows' => 6]) ?>

    <?= $form->field($model, 'recurring_appointment')->checkbox() ?>

    <?= $form->field($model, 'walk_in_appointment')->checkbox() ?>

    <?= $form->field($model, 'symptoms_brief')->textarea(['rows' => 6]) ?>

    <?php $form->field($model, 'created_at')->textInput() ?>

    <?php $form->field($model, 'updated_at')->textInput() ?>

    <?php $form->field($model, 'created_by')->textInput() ?>

    <?php $form->field($model, 'updated_by')->textInput() ?>

    <?= $form->field($model, 'consultant_id')->textInput(['readonly' => true, 'disabled' => true]) ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>