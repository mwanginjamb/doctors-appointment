<?php

use frontend\assets\CustomAsset;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * The appointments calendar. What it shows and allows is worked out in CalendarViewModel; the
 * calendar itself is Js/booking-calendar.js, configured from the JSON block below.
 *
 * @var yii\web\View $this
 * @var frontend\models\Appointments $model
 * @var frontend\models\CalendarViewModel $calendar
 */

$consultant = $calendar->consultant;
$booksForPatients = $calendar->booksForPatients;
$readOnly = $calendar->readOnly;
$this->title = $calendar->title;
?>
<div class="container mt-4">
    <div class="calendar-header text-center py-3">
        <h1 class="fw-bold display-5 text-primary"><?= Html::encode(ucwords($calendar->title)) ?> </h1>
        <p class="lead text-muted">
            <?php if ($calendar->deactivated): ?>
                <?= Html::encode($consultant->names) ?> is no longer taking appointments.
            <?php elseif ($readOnly): ?>
                View only. Click an appointment to open it; cancel it from there or from the appointments board.
            <?php else: ?>
                Please click on any available time slot to enter your appointment.
            <?php endif; ?>
        </p>
        <?php if ($booksForPatients && !$readOnly && !empty($consultant)): ?>
            <?= Html::a('Send a booking link', ['/booking-link/create', 'cid' => $consultant->id], [
                'class' => 'btn btn-outline-primary',
                'title' => 'Let a potential patient pick one of your free slots as a remote walk-in',
            ]) ?>
        <?php endif; ?>
    </div>
</div>
<div class="container">
    <div id="calendar"></div>
</div>


<!-- Modal for Booking -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="modalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalLabel">Book Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <?php $form = ActiveForm::begin(['id' => 'appointmentForm']); ?>

                <?php if ($booksForPatients): ?>
                    <!-- Consultants and front desk book for a registered patient, found by exact email or phone -->
                    <div class="mb-3">
                        <label class="form-label" for="patient-lookup"><?= Yii::t('app', 'Patient') ?></label>
                        <div class="input-group">
                            <input type="text" id="patient-lookup" class="form-control" autocomplete="off"
                                placeholder="<?= Yii::t('app', "Patient's email or phone number") ?>">
                            <button type="button" class="btn btn-outline-primary" id="patient-lookup-btn"><?= Yii::t('app', 'Find') ?></button>
                        </div>
                        <div id="patient-lookup-results" class="list-group mt-2"></div>
                        <div id="patient-selected" class="form-text fw-semibold"></div>
                        <button type="button" class="btn btn-link btn-sm px-0" id="walk-in-toggle"><?= Yii::t('app', 'Not registered? Register a walk-in patient') ?></button>
                        <div id="walk-in-form" class="border rounded p-2 mt-1 d-none">
                            <input type="text" id="walk-in-name" class="form-control form-control-sm mb-2" maxlength="150" placeholder="<?= Yii::t('app', 'Full name') ?>">
                            <input type="tel" id="walk-in-phone" class="form-control form-control-sm mb-2" maxlength="20" placeholder="<?= Yii::t('app', 'Phone, e.g. 0712 345 678') ?>">
                            <input type="email" id="walk-in-email" class="form-control form-control-sm mb-2" maxlength="255" placeholder="<?= Yii::t('app', 'Email (optional)') ?>">
                            <div class="form-text mb-2"><?= Yii::t('app', 'Without an email the patient gets reminders by SMS/WhatsApp only, and cannot sign in until an email is added.') ?></div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="walk-in-register"><?= Yii::t('app', 'Register and choose') ?></button>
                        </div>
                    </div>
                    <?= $form->field($model, 'patient_id')->hiddenInput(['value' => ''])->label(false) ?>
                <?php endif; ?>
                <?= $form->field($model, 'consultant_id')->hiddenInput(['value' => $consultant->user_id ?? null])->label(false) ?>
                <div class="row">
                    <div class="col-md-6">
                        <?= $form->field($model, 'date')->textInput(['readonly' => true]) ?>
                    </div>
                    <div class="col-md-6">
                        <?= $form->field($model, 'time')->textInput(['readonly' => true]) ?>
                    </div>
                </div>
                <?= $form->field($model, 'symptoms_brief')->textarea(['rows' => 6]) ?>


                <div class="form-group">
                    <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
                </div>

                <?php ActiveForm::end(); ?>
            </div>
        </div>
    </div>
</div>


<script type="application/json" id="booking-calendar-config"><?= json_encode($calendar->clientConfig(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
$this->registerJsFile('@web/Js/booking-calendar.js', ['depends' => [CustomAsset::class]]);
