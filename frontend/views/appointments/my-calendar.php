<?php

use frontend\models\Consultant;
use yii\web\View;
use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/**
 * @var yii\web\View $this
 */
$role = Yii::$app->user->identity->role;
$title = 'My Appointments Calendar - Read Only';
if ($role == 'client') {
    $title = 'Consultantations Appointments for ' . ucwords(Yii::$app->user->identity->full_name);
} else if ($role == 'consultant') {
    $consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
    $title = 'Consultantations Schedule for ' . ucwords($consultant->names);
}



$this->title = $title;

?>
<div class="container mt-4">
    <div class="calendar-header text-center py-3">
        <h1 class="fw-bold display-5 text-primary"><?= ucwords($title) ?> </h1>
        <p class="lead text-muted">Please click on any available time slot to enter your appointment.</p>
    </div>
</div>
<div class="container">
    <div id="calendar"></div>
</div>





<?php
$script = <<<JS

    var calendarEl = document.getElementById('calendar');
    var lastClickTime = 0;
    var doubleClickThreshold = 300; // milliseconds    
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek', // Day view - Default View
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' // Toggle buttons
        },
        events: fetchCalendarEvents, // Load existing appointments
        //selectable: true,
        editable: false,
        eventDurationEditable: true,
        height: 800,
        eventDidMount: function(info) {
                $(info.el).tooltip({
                title: info.event.extendedProps.description,
                placement: "top",
                trigger: "hover",
                container: "body"
            });
        },
        eventClick: function(info) {
             window.open("/appointments/view?id=" + info.event.id, '_blank');
        }
    });
    calendar.render();

    // Update event helper function

   function updateEvent(event) {
        var eventData = {
            id: event.id,
            date: event.start.toISOString().split('T')[0], // YYYY-MM-DD
            time: event.start.toTimeString().split(' ')[0] // HH:MM:SS
        };

        $.ajax({
            url: '/api/update-visit',
            type: 'POST',
            data: JSON.stringify(eventData),
            contentType: 'application/json',
            success: function(response) {
                alert(response.message);
            },
            error: function(xhr) {
                console.log(xhr);
                alert('Error updating appointment: ' + xhr.responseText);
                location.reload(); // Reload calendar if update fails
            }
        });
    }

    // Handle form submission
    $('#appointmentForm').on('submit', function(e) {
        e.preventDefault();
        let appointmentData = {
            date: $('#appointments-date').val(),
            time: $('#appointments-time').val(),
            brief: $('#appointments-symptoms_brief').val(),
            patient_name: $('#appointments-patient_id').val(),
            consultant: $('#appointments-consultant_id').val(),
        };

        $.ajax({
            url: '/api/visit',
            type: 'POST',
            data: JSON.stringify(appointmentData),
            contentType: 'application/json',
            success: function(response) {
                if(response.status === 'success'){
                    alert('Appointment booked successfully!');
                    $('#appointmentModal').modal('hide');
                    calendar.refetchEvents(); // Reload events from API
                } else {
                    alert(response.message || 'Error saving appointment.');
                }
            },
            error: function() {
                alert('Error saving appointment.');
            }
        });
    });

JS;
$this->registerJs($script);
?>