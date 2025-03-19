<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var app\models\Consultant $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="consultant-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->field($model, 'names')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'license_number')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'speciality')->textarea(['rows' => 6]) ?>

    <?= $form->field($model, 'sub_speciality')->textarea(['rows' => 6]) ?>

    <?= $form->field($model, 'kmpdc_registration_number')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'user_id')->textInput() ?>

    <?= $form->field($model, 'facility')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'physical_address')->textarea(['rows' => 6]) ?>

    <?= $form->field($model, 'created_at')->textInput() ?>

    <?= $form->field($model, 'updated_at')->textInput() ?>

    <?= $form->field($model, 'created_by')->textInput() ?>

    <?= $form->field($model, 'updated_by')->textInput() ?>

    <?= $form->field($model, 'consultant_id')->textInput() ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
