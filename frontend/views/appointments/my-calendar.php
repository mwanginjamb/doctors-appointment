<?php

use frontend\models\Consultant;
use yii\bootstrap5\Html;

/**
 * A read-only calendar of the signed-in user's appointments; clicking one opens it. Consultants
 * book on appointments/calendar, and block time on Availability (time off, weekly hours elsewhere).
 *
 * @var yii\web\View $this
 */
$me = Yii::$app->user->identity;
$title = 'My Appointments Calendar - Read Only';
if ($me->isPatientAccount()) {
    $title = 'Consultantations Appointments for ' . ucwords((string) $me->full_name);
} else if ($me->isConsultantAccount()) {
    $consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
    $title = 'Consultantations Schedule for ' . ucwords($consultant->names);
}



$this->title = $title;

?>
<div class="container mt-4">
    <div class="calendar-header text-center py-3">
        <h1 class="fw-bold display-5 text-primary"><?= Html::encode(ucwords($title)) ?> </h1>
        <p class="lead text-muted">
            <?= Yii::t('app', 'View only. Click an appointment to open it.') ?>
            <?php if ($me->isConsultantAccount()): ?>
                <?= Html::a(Yii::t('app', 'Book on your calendar'), ['appointments/calendar'], ['id' => 'my-calendar-book-link']) ?>
            <?php endif; ?>
        </p>
    </div>
</div>
<div class="container">
    <div id="calendar"></div>
</div>





<?php
$script = <<<JS

    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek', // Day view - Default View
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' // Toggle buttons
        },
        events: fetchCalendarEvents, // Load existing appointments
        editable: false,
        eventDurationEditable: false, // appointments keep the length they were booked with
        height: 800,
        eventDidMount: addEventTooltip,
        eventClick: function(info) {
            // Only appointments have an id; shaded blocks have none, so they open nothing
            if (!info.event.id) {
                var props = info.event.extendedProps;
                alert(props.reason || props.description || info.event.title || 'This time is not available.');
                return;
            }
            window.open("/appointments/view?id=" + encodeURIComponent(info.event.id), '_blank');
        }
    });
    calendar.render();

JS;
$this->registerJs($script);
?>