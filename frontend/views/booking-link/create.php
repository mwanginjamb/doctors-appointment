<?php

use frontend\models\BookingLink;
use frontend\models\BookingLinkForm;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var BookingLinkForm $form */

$this->title = 'Send a booking link';
$this->params['breadcrumbs'][] = ['label' => 'Booking links', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="booking-link-create container my-4" style="max-width: 44rem">

    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Send a potential patient a link to book with <strong><?= Html::encode($form->consultant->names) ?></strong>
        as a remote walk-in. They see only the free slots in the days and hours you choose, and the link works for
        <?= BookingLink::ttlMinutes() ?> minutes.
    </p>

    <?php $f = ActiveForm::begin(['id' => 'booking-link-form']); ?>

    <h2 class="h6 fw-bold mt-4">Who it is for</h2>
    <?= $f->field($form, 'recipient_name')->textInput(['maxlength' => 150]) ?>
    <div class="row g-2">
        <div class="col-md-6"><?= $f->field($form, 'recipient_phone')->input('tel', ['maxlength' => 20, 'placeholder' => '0712 345 678']) ?></div>
        <div class="col-md-6"><?= $f->field($form, 'recipient_email')->input('email', ['maxlength' => 255]) ?></div>
    </div>
    <p class="form-text mt-0">The link goes by WhatsApp to the number and by email to the address; give at least one. The booking stays tied to this contact.</p>

    <h2 class="h6 fw-bold mt-4">Slots to offer</h2>
    <?php $facilities = $form->facilityChoices(); ?>
    <?php if (count($facilities) > 1): ?>
        <?= $f->field($form, 'facility_id')->dropDownList($facilities, ['prompt' => ''])
            ->hint('The link offers the free slots at this facility, with its session length and fee.') ?>
    <?php else: ?>
        <?= Html::activeHiddenInput($form, 'facility_id') ?>
        <?= $f->field($form, 'facility_id', ['template' => '{error}'])->label(false) ?>
    <?php endif; ?>
    <div class="row g-2">
        <div class="col-6"><?= $f->field($form, 'from_date')->input('date', ['min' => date('Y-m-d')]) ?></div>
        <div class="col-6"><?= $f->field($form, 'to_date')->input('date', ['min' => date('Y-m-d')]) ?></div>
        <div class="col-6"><?= $f->field($form, 'day_start')->input('time') ?></div>
        <div class="col-6"><?= $f->field($form, 'day_end')->input('time') ?></div>
    </div>
    <p class="form-text mt-0">
        At most <?= BookingLinkForm::maxDays() ?> days. Leave the hours empty to offer the consultant's whole working day.
        Only free slots inside their working hours are offered.
    </p>

    <div class="form-group mt-3">
        <?= Html::submitButton('Send link', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-link']) ?>
    </div>

    <?php ActiveForm::end(); ?>
</div>
