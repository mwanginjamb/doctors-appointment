<?php

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\UserProfile $model */
/** @var yii\widgets\ActiveForm $form */
?>

<div class="border-top pt-1"></div>
<div class="user-profile-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->errorSummary($model); ?>

    <h2 class="h5 fw-bold mt-5 mb-1">Contact Information</h2>

    <div class="row">
        <div class="col-md-6 mb-1">
            <?= $form->field($model, 'phone')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-md-6 mb-1">
            <?= $form->field($model, 'email')->textInput(['maxlength' => true, 'type' => 'email']) ?>
        </div>
    </div>


    <h2 class="h5 fw-bold mt-5">Fees Settlement Details</h2>

    <div class="row">
        <div class="col-md-6 mb-1">
            <?= $form->field($model, 'cash')->checkbox() ?>
        </div>
        <div class="col-md-6 mb-1">
            <?= $form->field($model, 'insurance')->dropDownList($providers, ['prompt' => 'select ...', 'multiple' => 'multiple']) ?>
        </div>
    </div>

    <h2 class="h5 fw-bold mt-5">Bio Details</h2>

    <div class="row">
        <div class="col-md-6 mb-1">
            <?= $form->field($model, 'dob')->textInput(['type' => 'date']) ?>

        </div>
        <div class="col-md-6 mb-1">
            <?= $form->field($model, 'gender')->dropDownList($gender, ['prompt' => 'select ...']) ?>

        </div>



        <?= $form->field($model, 'user_id')->textInput(['type' => 'hidden'])->label(false) ?>
        <?php $form->field($model, 'created_at')->textInput() ?>

        <?php $form->field($model, 'updated_at')->textInput() ?>

        <?php $form->field($model, 'created_by')->textInput() ?>

        <?php $form->field($model, 'updated_by')->textInput() ?>

        <div class="form-group">
            <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
        </div>

        <?php ActiveForm::end(); ?>

    </div>


    <?php

    $script = <<<JS
        $(document).ready(function() {
             $('#userprofile-insurance').select2();
        });
    JS;
    $this->registerJs($script);

    ?>