<?php

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\Consultant $model */
/** @var yii\widgets\ActiveForm $form */
/** @var array $catalogue from Specialization::catalogue() */

use frontend\components\SpecializationPicker;

[$mainItems, $mainOptions] = SpecializationPicker::mainOptions($catalogue, $model->speciality);
[$subItems, $subOptions] = SpecializationPicker::subOptions($catalogue, $model->sub_specialities);
SpecializationPicker::register($this, '#consultant-speciality', '#consultant-sub_specialities');
?>

<div class="consultant-form">

    <?php $form = ActiveForm::begin(); ?>

    <div class="row">
        <div class="col-md-6">
            <?php
            // Suggest a name only for a new profile; never replace a saved one (or put an admin's name on it)
            if ($model->isNewRecord && !$model->names) {
                $model->names = 'Dr. ' . ucwords((string) Yii::$app->user->identity->full_name);
            }
            ?>
            <?= $form->field($model, 'names')->textInput(['maxlength' => true, 'autofocus' => true, 'placeholder' => 'Title Full Names']) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'facility')->textInput(['maxlength' => true]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'speciality')
                ->dropDownList($mainItems, ['prompt' => '', 'options' => $mainOptions])
                ->label('Area of Specialization')
                ->hint('Start typing to search. If yours is not listed, type it and choose "Use …".') ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'sub_specialities')
                ->dropDownList($subItems, ['multiple' => true, 'options' => $subOptions])
                ->hint('Pick as many as apply. Suggestions follow the area you chose; you can also type your own.') ?>
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
            <?= $form->field($model, 'license_number')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'practice_type')->textInput(['maxlength' => true]) ?>
            <?php $form->field($model, 'license_type')->textInput(['maxlength' => true]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'consultant_email')->textInput(['maxlength' => true]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'consultant_phone_number')->textInput(['maxlength' => true]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'gender')->dropDownList($gender, ['prompt' => 'Select ...']) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'practice_name')->textInput(['maxlength' => true]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <!-- Working hours are set on the Availability page -->
            <p class="form-label mb-1"><?= Yii::t('app', 'Working Hours') ?></p>
            <p class="form-text mt-0">
                <?= $model->isNewRecord
                    ? Yii::t('app', 'You start with Mon–Sat 08:00–13:00, 14:00–17:00. Change it on the Availability page after saving.')
                    : \yii\helpers\Html::encode($model->getHoursSummary() ?? Yii::t('app', 'Not taking bookings')) . ' · ' . \yii\helpers\Html::a(Yii::t('app', 'Change'), ['availability/index']) ?>
            </p>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'covers_supported')->dropDownList($providers, ['prompt' => 'Select ...', 'multiple' => true]) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'practice_establishment_date')->textInput(['type' => 'date']) ?>
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

<?php

$script = <<<JS
    $(document).ready(function() {
        $('#consultant-covers_supported').select2();
    });
JS;
$this->registerJs($script);

?>