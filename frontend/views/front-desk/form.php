<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\FrontDeskStaffForm $form */
/** @var array $consultants consultant.id => label */
/** @var array $byFacility facility id => consultant ids who work there */
/** @var array $facilities facility id => name */

$editing = $form->getUser() !== null;
$this->title = $editing ? 'Edit ' . ($form->full_name ?: 'front-desk staff') : 'Add Front-Desk Staff';
$this->params['breadcrumbs'][] = ['label' => 'Front Desk Staff', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="front-desk-form" style="max-width: 40rem">

    <h1><?= Html::encode($this->title) ?></h1>

    <?php $f = ActiveForm::begin(['id' => 'front-desk-staff-form']); ?>

    <?= $f->field($form, 'full_name')->textInput(['maxlength' => 150]) ?>

    <?= $editing
        ? $f->field($form, 'email')->textInput(['disabled' => true])
        : $f->field($form, 'email')->input('email') ?>

    <?= $f->field($form, 'phone')->input('tel', ['maxlength' => 15]) ?>

    <?= $f->field($form, 'password')->passwordInput(['autocomplete' => 'new-password'])
        ->hint($editing ? '' : 'Give it to them in person; they can change it with "forgot password".') ?>

    <?= $f->field($form, 'facility_id')->dropDownList($facilities, ['prompt' => 'Choose a facility', 'id' => 'front-desk-facility'])
        ->hint('They see, book and handle appointments only at this facility. Their consultants\' appointments elsewhere show as busy.') ?>

    <?= $f->field($form, 'consultant_ids')->checkboxList($consultants, ['id' => 'front-desk-consultants'])
        ->hint('Only consultants who work at the chosen facility are listed.') ?>

    <?php
    // Show only the consultants who work at the chosen facility (the server checks it again)
    $this->registerJs('(function () {
        var byFacility = ' . json_encode((object) $byFacility) . ';
        var select = document.getElementById("front-desk-facility");
        function filter() {
            var allowed = byFacility[select.value] || [];
            document.querySelectorAll("#front-desk-consultants input[type=checkbox]").forEach(function (box) {
                var shown = allowed.indexOf(parseInt(box.value, 10)) !== -1;
                box.closest(".form-check, label").style.display = shown ? "" : "none";
                if (!shown) { box.checked = false; }
            });
        }
        select.addEventListener("change", filter);
        filter();
    })();');
    ?>

    <div class="form-group">
        <?= Html::submitButton('Save', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-link']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
