<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\FrontDeskStaffForm $form */
/** @var array $consultants consultant.id => label, every consultant */
/** @var array $byFacility facility id => consultant ids who work there */
/** @var array $invited facility id => consultant ids invited to work there */
/** @var array $facilities facility id => name */
/** @var bool $locked a facility admin: the facility is theirs and cannot be changed */

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

    <?= $f->field($form, 'facility_id')->dropDownList($facilities, [
        'prompt' => $locked ? null : 'Choose a facility',
        'id' => 'front-desk-facility',
        'disabled' => $locked,
    ])->hint($locked
        ? 'Front desk you add works at your facility.'
        : 'They see, book and handle appointments only at this facility. Their consultants\' appointments elsewhere show as busy.') ?>

    <?= $f->field($form, 'consultant_ids')->checkboxList($consultants, ['id' => 'front-desk-consultants'])
        ->hint('Every consultant is listed. Ticking one who does not work at the facility yet invites them to: front desk can book for them there once they accept and add their hours.') ?>

    <?php
    // Mark each consultant "works here", "invited" or "not here yet" for the chosen facility
    $this->registerJs('(function () {
        var working = ' . json_encode((object) $byFacility) . ';
        var invited = ' . json_encode((object) $invited) . ';
        var select = document.getElementById("front-desk-facility");
        function mark() {
            var here = working[select.value] || [], asked = invited[select.value] || [];
            document.querySelectorAll("#front-desk-consultants input[type=checkbox]").forEach(function (box) {
                var id = parseInt(box.value, 10);
                var holder = box.closest(".form-check") || box.parentNode;
                var badge = holder.querySelector(".consultant-here");
                if (!badge) {
                    badge = document.createElement("span");
                    badge.className = "consultant-here badge ms-2";
                    holder.appendChild(badge);
                }
                if (!select.value) {
                    badge.textContent = "";
                    badge.className = "consultant-here badge ms-2 d-none";
                } else if (here.indexOf(id) !== -1) {
                    badge.textContent = "works here";
                    badge.className = "consultant-here badge ms-2 text-bg-success";
                } else if (asked.indexOf(id) !== -1) {
                    badge.textContent = "invited, waiting";
                    badge.className = "consultant-here badge ms-2 text-bg-warning";
                } else {
                    badge.textContent = "not here yet: ticking invites them";
                    badge.className = "consultant-here badge ms-2 text-bg-light border";
                }
            });
        }
        select.addEventListener("change", mark);
        mark();
    })();');
    ?>

    <div class="form-group">
        <?= Html::submitButton('Save', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-link']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
