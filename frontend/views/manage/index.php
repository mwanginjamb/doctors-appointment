<?php

use frontend\models\Appointments;
use frontend\services\SelfServiceLink;
use yii\bootstrap5\Html;

/**
 * The patient's self-service page: their appointment, and (outside the cut-off) a calendar of free
 * times with the same consultant to move to, and a cancel form.
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

$events = [];
$windowStart = '23:59';
$windowEnd = '00:00';
foreach ($slots as $date => $times) {
    foreach ($times as $time) {
        $start = strtotime("$date $time");
        $events[] = [
            'start' => date('Y-m-d\TH:i:s', $start),
            'end' => date('Y-m-d\TH:i:s', $start + $length * 60),
            'title' => 'Free',
            'extendedProps' => ['date' => $date, 'time' => $time, 'label' => date('l j F \a\t H:i', $start)],
        ];
        $windowStart = min($windowStart, $time);
        $windowEnd = max($windowEnd, date('H:i', $start + $length * 60));
    }
}
$dates = array_keys($slots);
?>
<div class="manage-index container my-4" style="max-width: 60rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="fs-5 fw-semibold"><?= Html::encode($when) ?></div>
            <div class="text-muted">
                <?= Html::encode($consultant->names ?? '') ?> · <?= $length ?> minutes
                <?= $consultant && $consultant->practice_name ? ' · ' . Html::encode($consultant->practice_name) : '' ?>
            </div>
            <?php if ($appointment->status === Appointments::STATUS_CANCELLED): ?>
                <div class="badge text-bg-secondary mt-2">Cancelled</div>
            <?php elseif (in_array($appointment->status, [Appointments::STATUS_COMPLETED, Appointments::STATUS_NO_SHOW], true) || strtotime($appointment->date . ' ' . $appointment->time) <= time()): ?>
                <div class="badge text-bg-dark mt-2">Past</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$changeable): ?>
        <?php if (!in_array($appointment->status, [Appointments::STATUS_CANCELLED, Appointments::STATUS_COMPLETED, Appointments::STATUS_NO_SHOW], true)
            && strtotime($appointment->date . ' ' . $appointment->time) > time()): ?>
            <div class="alert alert-info">
                Changes are possible up to <?= SelfServiceLink::cutoffHours() ?> hours before the appointment. Please call the clinic.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h2 class="h5 fw-bold">Choose another time</h2>
        <?php if (!$events): ?>
            <p class="text-muted">There are no other free times with <?= Html::encode($consultant->names ?? 'this doctor') ?> in the next <?= count($dates) ?> days. Please call the clinic.</p>
        <?php else: ?>
            <p class="text-muted small">Green times are free. Your current time stays booked until you choose a new one.</p>
            <div id="reschedule-calendar" class="mb-3"></div>
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
<?php
if ($changeable && $events) {
    $config = json_encode([
        'events' => $events,
        'from' => $dates[0],
        'days' => count($dates),
        'end' => date('Y-m-d', strtotime(end($dates) . ' +1 day')),
        'slotDuration' => sprintf('%02d:%02d:00', intdiv($length, 60), $length % 60),
        'slotMinTime' => $windowStart,
        'slotMaxTime' => $windowEnd,
    ]);
    $this->registerJs(<<<JS
    var cfg = {$config};
    new FullCalendar.Calendar(document.getElementById('reschedule-calendar'), {
        initialView: window.innerWidth < 768 ? 'listWeek' : 'timeGridWeek',
        initialDate: cfg.from,
        validRange: { start: cfg.from, end: cfg.end },
        headerToolbar: { left: 'prev,next', center: 'title', right: 'timeGridWeek,listWeek' },
        allDaySlot: false,
        slotDuration: cfg.slotDuration,
        slotMinTime: cfg.slotMinTime,
        slotMaxTime: cfg.slotMaxTime,
        selectable: false,
        editable: false,
        height: 'auto',
        events: cfg.events,
        eventColor: '#198754',
        eventClick: function (info) {
            var p = info.event.extendedProps;
            document.getElementById('reschedule-date').value = p.date;
            document.getElementById('reschedule-time').value = p.time;
            document.getElementById('reschedule-choice').textContent = 'New time: ' + p.label;
            document.getElementById('reschedule-button').disabled = false;
        }
    }).render();
JS);
    $this->registerCss('#reschedule-calendar .fc-timegrid-col { background: #f1f3f5; }');
}
