<?php

use frontend\models\ConsultantWorkingHours;
use yii\bootstrap5\Html;

/**
 * @var yii\web\View $this
 * @var frontend\models\WeeklyScheduleForm $form
 * @var frontend\models\Consultant $consultant
 */

$this->title = Yii::t('app', 'Availability');
$this->params['breadcrumbs'][] = $this->title;

$params = Yii::$app->params;
$summary = $consultant->getHoursSummary();

/** One editable time range; $day and $i make the input names unique. */
$rangeRow = function ($day, $i, $start = '', $end = '') {
    return '<div class="input-group input-group-sm mb-2 range-row" style="max-width: 22rem">'
        . Html::input('time', "hours[$day][$i][start]", $start, ['class' => 'form-control', 'required' => true, 'aria-label' => 'Opens'])
        . '<span class="input-group-text">to</span>'
        . Html::input('time', "hours[$day][$i][end]", $end, ['class' => 'form-control', 'required' => true, 'aria-label' => 'Closes'])
        . '<button type="button" class="btn btn-outline-danger remove-range" title="' . Yii::t('app', 'Remove') . '">&times;</button>'
        . '</div>';
};
?>
<div class="availability-index container my-4">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        <?= Yii::t('app', 'Patients can only book times inside these hours. Leave a day empty to close it; add two ranges for a break (e.g. 08:00–13:00 and 14:00–17:00).') ?>
    </p>

    <div class="alert alert-light border">
        <div><strong><?= Yii::t('app', 'Patients see') ?>:</strong> <?= Html::encode($summary ?? Yii::t('app', 'Closed every day')) ?></div>
        <div class="small text-muted mt-1">
            <?= Yii::t('app', 'Sessions are {m} minutes. Patients book at least {notice} minutes ahead and at most {days} days ahead; you can book walk-ins with no notice.', [
                'm' => (new \frontend\models\Appointments(['consultant_id' => $consultant->user_id]))->getDurationMinutes(),
                'notice' => (int) ($params['booking.minNoticeMinutes'] ?? 0),
                'days' => (int) ($params['booking.maxDaysAhead'] ?? 0),
            ]) ?>
        </div>
    </div>

    <?php foreach ($form->dayErrors as $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-danger py-2"><?= Html::encode($message) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?= Html::beginForm(['availability/index'], 'post', ['id' => 'weekly-schedule-form']) ?>
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
                                    <?= $rangeRow($day, $i, $range['start'], $range['end']) ?>
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
</div>

<template id="range-template"><?= $rangeRow('__DAY__', '__I__') ?></template>

<?php
$this->registerJs(<<<'JS'
var nextIndex = 1000; // unique input names for rows added in the browser

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
