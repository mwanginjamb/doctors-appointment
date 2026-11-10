<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * Public booking page a booking link opens. Only the free slots the link offers can be chosen;
 * days outside its range cannot be reached, and hours outside the slots are greyed out.
 *
 * @var yii\web\View $this
 * @var frontend\models\BookingLink $link
 * @var frontend\models\RemoteWalkInForm $form
 */

$consultant = $link->consultant;
$length = $consultant->getSessionMinutes();
$slots = $link->openSlots();
$days = count($slots);

$events = [];
$windowStart = '23:59';
$windowEnd = '00:00';
foreach ($slots as $date => $times) {
    foreach ($times as $time) {
        $start = strtotime("$date $time");
        $events[] = [
            'start' => date('Y-m-d\TH:i:s', $start),
            'end' => date('Y-m-d\TH:i:s', $start + $length * 60),
            'title' => 'Available',
            'extendedProps' => ['date' => $date, 'time' => $time, 'label' => date('l j F \a\t H:i', $start)],
        ];
        $windowStart = min($windowStart, $time);
        $windowEnd = max($windowEnd, date('H:i', $start + $length * 60));
    }
}

$this->title = 'Book with ' . $consultant->names;
$config = json_encode([
    'events' => $events,
    'from' => $link->from_date,
    'to' => date('Y-m-d', strtotime($link->to_date . ' +1 day')),
    'days' => $days,
    'slotDuration' => sprintf('%02d:%02d:00', intdiv($length, 60), $length % 60),
    'slotMinTime' => $events ? $windowStart : '08:00',
    'slotMaxTime' => $events ? $windowEnd : '17:00',
]);
?>
<div class="book-index container my-4">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted mb-1">
        <?= Html::encode(Yii::$app->name) ?> invited you to book an appointment of <?= $length ?> minutes.
        Choose one of the green times, then confirm your details.
    </p>
    <p class="small text-muted">This link works until <strong><?= date('H:i', $link->expires_at) ?></strong> and books one appointment.</p>

    <?php if (!$events): ?>
        <div class="alert alert-warning">There are no free times left in this link's range. Please contact the clinic for another time.</div>
    <?php else: ?>
        <div id="booking-calendar" class="mb-4"></div>

        <div class="card shadow-sm" id="booking-details">
            <div class="card-body">
                <h2 class="h5 fw-bold">Your details</h2>
                <p id="selected-slot" class="fw-semibold text-primary mb-3">Choose a time above.</p>

                <?php $f = ActiveForm::begin(['id' => 'remote-walk-in-form']); ?>
                <?= Html::activeHiddenInput($form, 'date') ?>
                <?= Html::activeHiddenInput($form, 'time') ?>
                <?= $f->field($form, 'time', ['template' => '{error}'])->label(false) ?>
                <div class="row g-2">
                    <div class="col-md-6"><?= $f->field($form, 'full_name')->textInput(['maxlength' => 150]) ?></div>
                    <div class="col-md-6"><?= $f->field($form, 'phone')->input('tel', ['maxlength' => 20, 'readonly' => $form->isLocked('phone')]) ?></div>
                    <div class="col-md-6"><?= $f->field($form, 'email')->input('email', ['maxlength' => 255, 'readonly' => $form->isLocked('email')]) ?></div>
                </div>
                <?= $f->field($form, 'reason')->textarea(['rows' => 2, 'maxlength' => 500]) ?>
                <?= Html::submitButton('Book this time', ['class' => 'btn btn-primary', 'id' => 'book-button', 'disabled' => !$form->time]) ?>
                <?php ActiveForm::end(); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
if ($events) {
    $this->registerJs(<<<JS
    var cfg = {$config};
    var calendar = new FullCalendar.Calendar(document.getElementById('booking-calendar'), {
        initialView: window.innerWidth < 768 ? 'listRange' : 'gridRange',
        initialDate: cfg.from,
        validRange: { start: cfg.from, end: cfg.to },
        views: {
            gridRange: { type: 'timeGrid', duration: { days: cfg.days }, buttonText: 'calendar' },
            listRange: { type: 'list', duration: { days: cfg.days }, buttonText: 'list' }
        },
        headerToolbar: { left: '', center: 'title', right: 'gridRange,listRange' },
        allDaySlot: false,
        slotDuration: cfg.slotDuration,
        slotMinTime: cfg.slotMinTime,
        slotMaxTime: cfg.slotMaxTime,
        // Only the offered slots are open; everything else in the grid is greyed out
        businessHours: false,
        selectable: false,
        editable: false,
        height: 'auto',
        events: cfg.events,
        eventColor: '#198754',
        eventClick: function (info) {
            var p = info.event.extendedProps;
            document.getElementById('remotewalkinform-date').value = p.date;
            document.getElementById('remotewalkinform-time').value = p.time;
            document.getElementById('selected-slot').textContent = 'Your time: ' + p.label;
            document.getElementById('book-button').disabled = false;
            document.getElementById('booking-details').scrollIntoView({ behavior: 'smooth' });
        }
    });
    calendar.render();
JS);
    // Grey out the grid behind the open slots so they stand out
    $this->registerCss('#booking-calendar .fc-timegrid-col { background: #f1f3f5; }');
}
