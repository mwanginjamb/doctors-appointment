<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Facility $model */
/** @var bool $contactOnly a facility admin: only the address, phone and map link can change */
$contactOnly = $contactOnly ?? false;
?>

<div class="facility-form" style="max-width: 40rem">

    <?php $form = ActiveForm::begin(['id' => 'facility-form']); ?>

    <?php if (!$contactOnly): ?>
    <?= $form->field($model, 'name')->textInput(['maxlength' => 150, 'placeholder' => 'e.g. Nairobi Hospital, Doctors Plaza']) ?>
    <?php endif; ?>

    <?= $form->field($model, 'address')->textarea(['rows' => 2, 'maxlength' => 1000, 'placeholder' => 'Building, floor, room and road']) ?>

    <div class="row g-2">
        <div class="col-md-5"><?= $form->field($model, 'phone')->input('tel', ['maxlength' => 20, 'placeholder' => '0712 345 678']) ?></div>
        <div class="col-md-7"><?= $form->field($model, 'map_url')->input('url', ['maxlength' => 500, 'placeholder' => 'https://maps.app.goo.gl/…']) ?></div>
    </div>

    <?php if (!$contactOnly): ?>
    <?= $form->field($model, 'ownership')->dropDownList(\frontend\models\Facility::ownershipLabels(), ['prompt' => 'Not set'])
        ->hint('Consultants see facilities grouped by this when choosing theirs.') ?>

    <?= $form->field($model, 'active')->checkbox() ?>
    <?php endif; ?>

    <div class="form-group">
        <?= Html::submitButton('Save', ['class' => 'btn btn-success']) ?>
        <?php if (!$contactOnly): ?>
            <?= Html::a('Back', [Yii::$app->controller->action->id === 'create-from-request' ? 'requests' : 'index'], ['class' => 'btn btn-link']) ?>
        <?php endif; ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
