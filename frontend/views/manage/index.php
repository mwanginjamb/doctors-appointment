<?php

use frontend\components\SlotPicker;
use frontend\models\Appointments;
use frontend\services\SelfServiceLink;
use yii\bootstrap5\Html;

/**
 * The patient's self-service page: their appointment, a "Yes, I'm coming" button while it is still
 * only scheduled, and (outside the cut-off) a calendar of free times with the same consultant to
 * move to, and a cancel form.
 *
 * @var yii\web\View $this
 * @var Appointments $appointment
 * @var string $sig
 * @var bool $changeable
 * @var array $slots Y-m-d => 'H:i' start times
 */

$consultant = $appointment->consultant;
$when = date('l j F Y \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time));
$length = (int) $appointment->getDurationMinutes();
$this->title = 'Your appointment';

$hasSlots = (bool) array_filter($slots);
$dates = array_keys($slots);
?>
<div class="manage-index container my-4" style="max-width: 60rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="fs-5 fw-semibold"><?= Html::encode($when) ?></div>
            <div class="text-muted">
                <?= Html::encode($consultant->names ?? '') ?> · <?= $length ?> minutes
                <?= $appointment->getFeeLabel() ? ' · ' . Html::encode($appointment->getFeeLabel()) : '' ?>
            </div>
            <?= $this->render('//facility/_place', ['facility' => $appointment->facility, 'fallback' => $consultant->practice_name ?? null]) ?>
            <?php if ($appointment->status === Appointments::STATUS_CANCELLED): ?>
                <div class="badge text-bg-secondary mt-2">Cancelled</div>
            <?php elseif ($appointment->status === Appointments::STATUS_CONFIRMED && strtotime($appointment->date . ' ' . $appointment->time) > time()): ?>
                <div class="badge text-bg-success mt-2" id="confirmed-badge">Confirmed: you are coming</div>
            <?php elseif (in_array($appointment->status, [Appointments::STATUS_COMPLETED, Appointments::STATUS_NO_SHOW], true) || strtotime($appointment->date . ' ' . $appointment->time) <= time()): ?>
                <div class="badge text-bg-dark mt-2">Past</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (SelfServiceLink::canConfirm($appointment)): ?>
        <!-- The patient confirms they are coming (until the start; not limited by the cut-off) -->
        <div class="card border-success mb-4" id="confirm-card">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <span class="me-auto">Are you coming? Let <?= Html::encode($consultant->names ?? 'the clinic') ?> know.</span>
                <?= Html::beginForm(['manage/confirm', 'id' => $appointment->id, 'sig' => $sig], 'post', ['id' => 'self-confirm-form']) ?>
                    <?= Html::submitButton("Yes, I'm coming", ['class' => 'btn btn-success']) ?>
                <?= Html::endForm() ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$changeable): ?>
        <?php if (!in_array($appointment->status, [Appointments::STATUS_CANCELLED, Appointments::STATUS_COMPLETED, Appointments::STATUS_NO_SHOW], true)
            && strtotime($appointment->date . ' ' . $appointment->time) > time()): ?>
            <div class="alert alert-info">
                Changes are possible up to <?= SelfServiceLink::cutoffHours() ?> hours before the appointment. Please call the clinic.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h2 class="h5 fw-bold">Choose another time</h2>
        <?php if (!$hasSlots): ?>
            <p class="text-muted">There are no other free times with <?= Html::encode($consultant->names ?? 'this doctor') ?><?= $appointment->facility ? ' at ' . Html::encode($appointment->facility->name) : '' ?> in the next <?= count($dates) ?> days. Please call the clinic<?= $appointment->facility && $appointment->facility->phone ? ' on ' . Html::encode($appointment->facility->phone) : '' ?>.</p>
        <?php else: ?>
            <p class="text-muted small">Green times are free. Your current time stays booked until you choose a new one.</p>
            <?= SlotPicker::widget([
                'slots' => $slots,
                'minutes' => $length,
                'weekly' => true,
                'targets' => [
                    'dateInput' => 'reschedule-date',
                    'timeInput' => 'reschedule-time',
                    'label' => 'reschedule-choice',
                    'button' => 'reschedule-button',
                ],
                'labelPrefix' => 'New time: ',
                'options' => ['class' => 'mb-3'],
            ]) ?>
            <?= Html::beginForm(['manage/reschedule', 'id' => $appointment->id, 'sig' => $sig], 'post', ['id' => 'reschedule-form', 'class' => 'mb-5']) ?>
                <?= Html::hiddenInput('date', '', ['id' => 'reschedule-date']) ?>
                <?= Html::hiddenInput('time', '', ['id' => 'reschedule-time']) ?>
                <p id="reschedule-choice" class="fw-semibold text-primary">Choose a time above.</p>
                <?= Html::submitButton('Move my appointment', ['class' => 'btn btn-primary', 'id' => 'reschedule-button', 'disabled' => true]) ?>
            <?= Html::endForm() ?>
        <?php endif; ?>

        <h2 class="h5 fw-bold">Cancel</h2>
        <?= Html::beginForm(['manage/cancel', 'id' => $appointment->id, 'sig' => $sig], 'post', ['id' => 'self-cancel-form']) ?>
            <label class="form-label" for="cancel-reason">Reason (optional, shared with the clinic)</label>
            <textarea id="cancel-reason" name="reason" class="form-control mb-3" rows="2" maxlength="400"></textarea>
            <?= Html::submitButton('Cancel my appointment', [
                'class' => 'btn btn-outline-danger',
                'data' => ['confirm' => 'Cancel your appointment on ' . $when . '?'],
            ]) ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
