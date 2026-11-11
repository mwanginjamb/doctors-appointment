<?php

use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\PublicHoliday;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * @var yii\web\View $this
 * @var frontend\models\WeeklyScheduleForm $form
 * @var frontend\models\Consultant $consultant
 * @var frontend\models\TimeOffForm $timeOffForm
 * @var frontend\models\ConsultantTimeOff[] $timeOff
 * @var ConsultantFacility $newEngagement the "add a facility" form
 * @var frontend\models\FacilityRequest $facilityRequest the "request a facility" form
 * @var frontend\models\FacilityRequest[] $facilityRequests the consultant's waiting and recently answered requests
 * @var int|null $cid consultant.id when an admin manages someone else's availability
 */

if ($cid) {
    $this->title = Yii::t('app', 'Availability: {name}', ['name' => $consultant->names]);
    $this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Consultants'), 'url' => ['manage-consultants/index']];
} else {
    $this->title = Yii::t('app', 'Availability');
}
$this->params['breadcrumbs'][] = $this->title;

$params = Yii::$app->params;
$engagements = $consultant->engagements;
$several = count($engagements) > 1;
$facilityChoices = [];
foreach ($engagements as $engagement) {
    $facilityChoices[$engagement->facility_id] = $engagement->facility->name;
}
$lengthChoices = array_combine(ConsultantFacility::sessionLengths(), array_map(function ($m) {
    return $m < 60 ? "$m min" : ($m % 60 ? floor($m / 60) . ' h ' . ($m % 60) . ' min' : ($m / 60) . ($m === 60 ? ' hour' : ' hours'));
}, ConsultantFacility::sessionLengths()));
$otherFacilities = Facility::activeList(array_keys($facilityChoices));

/** One editable time range; $day and $i make the input names unique. */
$rangeRow = function ($day, $i, $start = '', $end = '', $facility = null) use ($several, $facilityChoices) {
    $where = $several
        ? Html::dropDownList("hours[$day][$i][facility]", $facility, $facilityChoices, [
            'class' => 'form-select', 'required' => true, 'prompt' => Yii::t('app', 'Where?'), 'aria-label' => 'Facility',
        ])
        : Html::hiddenInput("hours[$day][$i][facility]", $facility ?? key($facilityChoices));

    return '<div class="input-group input-group-sm mb-2 range-row" style="max-width: ' . ($several ? '38rem' : '22rem') . '">'
        . Html::input('time', "hours[$day][$i][start]", $start, ['class' => 'form-control', 'required' => true, 'aria-label' => 'Opens'])
        . '<span class="input-group-text">to</span>'
        . Html::input('time', "hours[$day][$i][end]", $end, ['class' => 'form-control', 'required' => true, 'aria-label' => 'Closes'])
        . ($several ? '<span class="input-group-text">at</span>' : '') . $where
        . '<button type="button" class="btn btn-outline-danger remove-range" title="' . Yii::t('app', 'Remove') . '">&times;</button>'
        . '</div>';
};
?>
<div class="availability-index container my-4">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        <?= Yii::t('app', 'Patients can only book times inside these hours. Leave a day empty to close it; add two ranges for a break (e.g. 08:00–13:00 and 14:00–17:00). Working at several facilities? Add each one below, then say where you are for each range of hours.') ?>
    </p>

    <div class="alert alert-light border">
        <div class="fw-semibold mb-1"><?= Yii::t('app', 'Patients see') ?>:</div>
        <?php foreach ($consultant->getHoursByFacility() as $row): ?>
            <div>
                <span class="d-inline-block rounded-circle me-1" style="width:.65rem;height:.65rem;background:<?= Html::encode($row['engagement']->facility->getColor()) ?>"></span>
                <strong><?= Html::encode($row['engagement']->facility->name) ?></strong>:
                <?= Html::encode($row['summary'] ?? Yii::t('app', 'no hours yet')) ?>
            </div>
        <?php endforeach; ?>
        <div class="small text-muted mt-1">
            <?= Yii::t('app', 'Patients book at least {notice} minutes ahead and at most {days} days ahead; you can book walk-ins with no notice.', [
                'notice' => (int) ($params['booking.minNoticeMinutes'] ?? 0),
                'days' => (int) ($params['booking.maxDaysAhead'] ?? 0),
            ]) ?>
        </div>
    </div>

    <h2 class="h4 fw-bold mt-4" id="facilities"><?= Yii::t('app', 'Facilities') ?></h2>
    <p class="text-muted"><?= Yii::t('app', 'Where you see patients, with the session length and fee at each. Changes apply to new bookings; appointments already booked keep their length and fee.') ?></p>

    <div class="card shadow-sm mb-4">
        <ul class="list-group list-group-flush">
            <?php foreach ($engagements as $engagement): ?>
                <?php $facility = $engagement->facility; ?>
                <li class="list-group-item py-3 engagement-row" data-facility="<?= (int) $facility->id ?>">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="d-inline-block rounded-circle me-1" style="width:.75rem;height:.75rem;background:<?= Html::encode($facility->getColor()) ?>"></span>
                            <span class="fw-semibold"><?= Html::encode($facility->name) ?></span>
                            <?php if (!$facility->isActive()): ?>
                                <span class="badge text-bg-secondary"><?= Yii::t('app', 'Facility closed') ?></span>
                            <?php endif; ?>
                            <?php if ($facility->address): ?>
                                <div class="small text-muted"><?= Html::encode(preg_replace('/\s*\R\s*/', ', ', $facility->address)) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($several): ?>
                            <?= Html::a(Yii::t('app', 'Remove'), ['availability/remove-facility', 'id' => $engagement->id, 'cid' => $cid], [
                                'class' => 'btn btn-sm btn-outline-danger',
                                'data' => ['method' => 'post', 'confirm' => Yii::t('app', 'Stop working at {facility}?', ['facility' => $facility->name])],
                            ]) ?>
                        <?php endif; ?>
                    </div>
                    <?= Html::beginForm(['availability/update-facility', 'id' => $engagement->id, 'cid' => $cid], 'post', ['class' => 'row g-2 align-items-end engagement-form']) ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1"><?= Yii::t('app', 'Session length') ?></label>
                            <?= Html::activeDropDownList($engagement, 'session_minutes', $lengthChoices, ['class' => 'form-select form-select-sm', 'id' => null]) ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1"><?= Html::encode($engagement->getAttributeLabel('fee')) ?></label>
                            <?= Html::activeInput('text', $engagement, 'fee', ['class' => 'form-control form-control-sm', 'id' => null, 'inputmode' => 'decimal', 'placeholder' => Yii::t('app', 'Not set')]) ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1"><?= Yii::t('app', 'From') ?></label>
                            <?= Html::activeInput('date', $engagement, 'from_date', ['class' => 'form-control form-control-sm', 'id' => null]) ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1"><?= Yii::t('app', 'Until') ?></label>
                            <?= Html::activeInput('date', $engagement, 'to_date', ['class' => 'form-control form-control-sm', 'id' => null]) ?>
                        </div>
                        <div class="col-8 col-md-2">
                            <div class="form-check">
                                <?= Html::activeCheckbox($engagement, 'booking_open', ['class' => 'form-check-input', 'id' => 'booking-open-' . $engagement->id, 'label' => null]) ?>
                                <label class="form-check-label small" for="booking-open-<?= $engagement->id ?>"><?= Yii::t('app', 'Patients book online') ?></label>
                            </div>
                        </div>
                        <div class="col-4 col-md-2 text-end">
                            <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-sm btn-outline-primary']) ?>
                        </div>
                    <?= Html::endForm() ?>
                </li>
            <?php endforeach; ?>

            <li class="list-group-item py-3 bg-body-tertiary" id="add-facility">
                <div class="fw-semibold mb-2"><?= Yii::t('app', 'Add a facility you work at') ?></div>
                <?php $requesting = !$otherFacilities || $facilityRequest->hasErrors(); ?>
                <?php foreach ($newEngagement->getFirstErrors() as $error): ?>
                    <div class="alert alert-danger py-1 small"><?= Html::encode($error) ?></div>
                <?php endforeach; ?>
                <?php if ($otherFacilities): ?>
                    <?= Html::beginForm(['availability/add-facility', 'cid' => $cid], 'post', ['class' => 'row g-2 align-items-end', 'id' => 'add-facility-form']) ?>
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="new-facility"><?= Yii::t('app', 'Facility') ?></label>
                            <?= Html::activeDropDownList($newEngagement, 'facility_id', Facility::activeGroupedList(array_keys($facilityChoices)) + ['request' => Yii::t('app', 'Not listed: request it…')], [
                                'class' => 'form-select form-select-sm', 'id' => 'new-facility', 'prompt' => '', 'required' => true,
                                'value' => $requesting ? 'request' : $newEngagement->facility_id,
                            ]) ?>
                        </div>
                        <div class="col-6 col-md-2 engagement-field<?= $requesting ? ' d-none' : '' ?>">
                            <label class="form-label small mb-1"><?= Yii::t('app', 'Session length') ?></label>
                            <?= Html::activeDropDownList($newEngagement, 'session_minutes', $lengthChoices, ['class' => 'form-select form-select-sm', 'id' => null]) ?>
                        </div>
                        <div class="col-6 col-md-2 engagement-field<?= $requesting ? ' d-none' : '' ?>">
                            <label class="form-label small mb-1"><?= Html::encode($newEngagement->getAttributeLabel('fee')) ?></label>
                            <?= Html::activeInput('text', $newEngagement, 'fee', ['class' => 'form-control form-control-sm', 'id' => null, 'inputmode' => 'decimal']) ?>
                        </div>
                        <div class="col-8 col-md-2 engagement-field<?= $requesting ? ' d-none' : '' ?>">
                            <div class="form-check">
                                <?= Html::activeCheckbox($newEngagement, 'booking_open', ['class' => 'form-check-input', 'id' => 'new-booking-open', 'label' => null]) ?>
                                <label class="form-check-label small" for="new-booking-open"><?= Yii::t('app', 'Patients book online') ?></label>
                            </div>
                        </div>
                        <div class="col-4 col-md-2 text-end engagement-field<?= $requesting ? ' d-none' : '' ?>">
                            <?= Html::submitButton(Yii::t('app', 'Add'), ['class' => 'btn btn-sm btn-primary']) ?>
                        </div>
                    <?= Html::endForm() ?>
                <?php else: ?>
                    <p class="small text-muted"><?= Yii::t('app', 'No other facilities are listed yet. Ask for yours to be added:') ?></p>
                <?php endif; ?>

                <!-- Not on the list: ask the administrator to add it -->
                <div id="facility-request" class="border rounded bg-body p-3 mt-2<?= $requesting ? '' : ' d-none' ?>">
                    <div class="fw-semibold small mb-1"><?= Yii::t('app', 'Request a facility') ?></div>
                    <p class="small text-muted mb-2"><?= Yii::t('app', 'The administrator adds it to the list, or declines with a reason. You are told by email and WhatsApp; once it is added it appears under your facilities.') ?></p>
                    <?php $rf = ActiveForm::begin(['id' => 'facility-request-form', 'action' => ['availability/request-facility', 'cid' => $cid]]); ?>
                    <div class="row g-2">
                        <div class="col-md-6"><?= $rf->field($facilityRequest, 'name')->textInput(['maxlength' => 150, 'placeholder' => 'e.g. Nairobi Hospital, Doctors Plaza']) ?></div>
                        <div class="col-md-6"><?= $rf->field($facilityRequest, 'address')->textInput(['maxlength' => 1000, 'placeholder' => 'Building, floor, room and road']) ?></div>
                        <div class="col-md-4"><?= $rf->field($facilityRequest, 'phone')->input('tel', ['maxlength' => 20]) ?></div>
                        <div class="col-md-8"><?= $rf->field($facilityRequest, 'map_url')->input('url', ['maxlength' => 500, 'placeholder' => 'https://maps.app.goo.gl/…']) ?></div>
                    </div>
                    <?= $rf->field($facilityRequest, 'note')->textInput(['maxlength' => 500]) ?>
                    <?= Html::submitButton(Yii::t('app', 'Send request'), ['class' => 'btn btn-sm btn-outline-primary']) ?>
                    <?php ActiveForm::end(); ?>
                </div>
            </li>

            <?php if ($facilityRequests): ?>
                <li class="list-group-item py-3" id="facility-requests">
                    <div class="fw-semibold small mb-2"><?= Yii::t('app', 'Your facility requests') ?></div>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($facilityRequests as $request): ?>
                            <li class="mb-1 facility-request-<?= Html::encode($request->status) ?>">
                                <?= Html::encode($request->name) ?> —
                                <?php if ($request->isPending()): ?>
                                    <span class="badge text-bg-warning"><?= Yii::t('app', 'Waiting') ?></span>
                                    <span class="text-muted"><?= Yii::t('app', 'sent {date}', ['date' => date('j M', $request->created_at)]) ?></span>
                                <?php elseif ($request->status === \frontend\models\FacilityRequest::STATUS_CREATED): ?>
                                    <span class="badge text-bg-success"><?= Yii::t('app', 'Added') ?></span>
                                    <span class="text-muted"><?= Yii::t('app', 'add your hours there below') ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary"><?= Yii::t('app', 'Declined') ?></span>
                                    <?php if ($request->decision_note): ?>
                                        <span class="text-muted"><?= Html::encode($request->decision_note) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <h2 class="h4 fw-bold mt-4" id="weekly-hours"><?= Yii::t('app', 'Weekly hours') ?></h2>
    <?php if ($several): ?>
        <p class="text-muted small"><?= Yii::t('app', 'Choose where you are for each range. Ranges cannot overlap, even at different facilities; leave a gap to travel between them.') ?></p>
    <?php endif; ?>

    <?php foreach ($form->dayErrors as $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-danger py-2"><?= Html::encode($message) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?= Html::beginForm(['availability/index', 'cid' => $cid], 'post', ['id' => 'weekly-schedule-form']) ?>
    <?= Html::hiddenInput('submitted', 1) ?>
    <div class="card shadow-sm">
        <ul class="list-group list-group-flush">
            <?php foreach (ConsultantWorkingHours::DAYS as $day => $name): ?>
                <?php $ranges = $form->hours[$day] ?? []; ?>
                <li class="list-group-item py-3 day-row<?= isset($form->dayErrors[$day]) ? ' bg-danger-subtle' : '' ?>" data-day="<?= $day ?>">
                    <div class="row align-items-start">
                        <div class="col-md-2 fw-semibold pt-1"><?= Yii::t('app', $name) ?></div>
                        <div class="col-md-10">
                            <div class="ranges">
                                <?php foreach ($ranges as $i => $range): ?>
                                    <?= $rangeRow($day, $i, $range['start'], $range['end'], $range['facility'] ?? null) ?>
                                <?php endforeach; ?>
                            </div>
                            <span class="badge text-bg-secondary closed-label<?= $ranges ? ' d-none' : '' ?>"><?= Yii::t('app', 'Closed') ?></span>
                            <button type="button" class="btn btn-link btn-sm add-range"><?= Yii::t('app', '+ Add hours') ?></button>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="mt-3">
        <?= Html::submitButton(Yii::t('app', 'Save working hours'), ['class' => 'btn btn-primary']) ?>
    </div>
    <?= Html::endForm() ?>

    <h2 class="h4 fw-bold mt-5" id="time-off"><?= Yii::t('app', 'Time off') ?></h2>
    <p class="text-muted"><?= Yii::t('app', 'Leave, conferences or part of a day. Patients cannot book you during it; they only see the dates, never the reason.') ?></p>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3"><?= Yii::t('app', 'Add time off') ?></h3>
                    <?php $tf = ActiveForm::begin(['id' => 'time-off-form', 'action' => ['availability/add-time-off', 'cid' => $cid]]); ?>
                    <div class="row g-2">
                        <div class="col-6"><?= $tf->field($timeOffForm, 'start_date')->input('date') ?></div>
                        <div class="col-6"><?= $tf->field($timeOffForm, 'start_time')->input('time') ?></div>
                        <div class="col-6"><?= $tf->field($timeOffForm, 'end_date')->input('date') ?></div>
                        <div class="col-6"><?= $tf->field($timeOffForm, 'end_time')->input('time') ?></div>
                    </div>
                    <p class="form-text mt-0"><?= Yii::t('app', 'Leave the times empty to take whole days, from the first date to the last date inclusive.') ?></p>
                    <?= $tf->field($timeOffForm, 'reason')->textInput(['maxlength' => 255, 'placeholder' => Yii::t('app', 'e.g. Annual leave')]) ?>
                    <?= Html::submitButton(Yii::t('app', 'Add time off'), ['class' => 'btn btn-outline-primary']) ?>
                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if (!$timeOff): ?>
                <p class="text-muted"><?= Yii::t('app', 'No time off coming up.') ?></p>
            <?php endif; ?>
            <?php foreach ($timeOff as $period): ?>
                <?php $clashes = $period->findClashingAppointments(); ?>
                <div class="card shadow-sm mb-3 time-off-entry<?= $clashes ? ' border-warning' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?= Html::encode($period->getLabel()) ?></div>
                                <?php if ($period->reason): ?>
                                    <div class="text-muted small"><?= Html::encode($period->reason) ?></div>
                                <?php endif; ?>
                            </div>
                            <?= Html::a(Yii::t('app', 'Remove'), ['availability/delete-time-off', 'id' => $period->id, 'cid' => $cid], [
                                'class' => 'btn btn-sm btn-outline-danger',
                                'data' => ['method' => 'post', 'confirm' => Yii::t('app', 'Remove this time off? Patients will be able to book these times again.')],
                            ]) ?>
                        </div>
                        <?php if ($clashes): ?>
                            <div class="alert alert-warning small mt-3 mb-0">
                                <div class="fw-semibold mb-1">
                                    <?= Yii::t('app', '{n} appointment(s) booked during this time. Open each one to cancel it with a note, or drag it to a new time on your calendar.', ['n' => count($clashes)]) ?>
                                </div>
                                <ul class="mb-0">
                                    <?php foreach ($clashes as $appointment): ?>
                                        <li>
                                            <?= Html::a(
                                                Html::encode(Yii::$app->formatter->asDatetime(strtotime($appointment->date . ' ' . $appointment->time), 'php:D j M, H:i')
                                                    . ' — ' . ($appointment->patient->full_name ?? Yii::t('app', 'Patient'))),
                                                ['appointments/view', 'id' => $appointment->id]
                                            ) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php $holidays = PublicHoliday::between(date('Y-m-d'), date('Y-m-d', strtotime('+' . (int) ($params['booking.maxDaysAhead'] ?? 90) . ' days'))); ?>
            <?php if ($holidays): ?>
                <h3 class="h6 fw-bold mt-4"><?= Yii::t('app', 'Public holidays (clinic closed)') ?></h3>
                <ul class="list-unstyled small text-muted">
                    <?php foreach ($holidays as $holiday): ?>
                        <li><?= Html::encode(Yii::$app->formatter->asDate(strtotime($holiday->date), 'php:D j M Y') . ' — ' . $holiday->name) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<template id="range-template"><?= $rangeRow('__DAY__', '__I__') ?></template>

<?php
$this->registerJs(<<<'JS'
var nextIndex = 1000; // unique input names for rows added in the browser

// "Not listed": swap the add-a-facility fields for the request form
var newFacility = document.getElementById('new-facility');
if (newFacility) {
    newFacility.addEventListener('change', function () {
        var requesting = newFacility.value === 'request';
        document.querySelectorAll('#add-facility-form .engagement-field').forEach(function (el) {
            el.classList.toggle('d-none', requesting);
        });
        document.getElementById('facility-request').classList.toggle('d-none', !requesting);
        if (requesting) {
            document.querySelector('#facility-request-form input').focus();
        }
    });
}

function refreshClosed(dayRow) {
    var empty = dayRow.querySelectorAll('.range-row').length === 0;
    dayRow.querySelector('.closed-label').classList.toggle('d-none', !empty);
}

document.querySelectorAll('.day-row .add-range').forEach(function (button) {
    button.addEventListener('click', function () {
        var dayRow = button.closest('.day-row');
        var html = document.getElementById('range-template').innerHTML
            .replace(/__DAY__/g, dayRow.dataset.day)
            .replace(/__I__/g, String(nextIndex++));
        dayRow.querySelector('.ranges').insertAdjacentHTML('beforeend', html);
        refreshClosed(dayRow);
    });
});

document.getElementById('weekly-schedule-form').addEventListener('click', function (e) {
    var remove = e.target.closest('.remove-range');
    if (remove) {
        var dayRow = remove.closest('.day-row');
        remove.closest('.range-row').remove();
        refreshClosed(dayRow);
    }
});
JS);
