<?php

use yii\web\View;
use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/**
 * @var yii\web\View $this
 */
$title = 'Consultantations Schedule for ' . ucwords($consultant->names);



$this->title = $title;

?>
<div class="container mt-4">
    <div class="calendar-header text-center py-3">
        <h1 class="fw-bold display-5 text-primary"><?= ucwords($consultant->names) ?> Appointment Calendar</h1>
        <p class="lead text-muted">Please click on any available time slot to enter your appointment.</p>
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

                <?= $form->field($model, 'patient_id')->hiddenInput(['value' => Yii::$app->user->id])->label(false) ?>
                <?= $form->field($model, 'consultant_id')->hiddenInput(['value' => $consultant->id ?? null])->label(false) ?>
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


<?php
$script = <<<JS

    var calendarEl = document.getElementById('calendar');
    var lastClickTime = 0;
    var doubleClickThreshold = 300; // milliseconds
    
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek', // Day view - Default View
        validRange: function() {
            let nowDate = new Date(); // Get current date
            return {
                start: nowDate,
                end: new Date(nowDate.getFullYear(), nowDate.getMonth() + 3, nowDate.getDate())
            };
        },
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' // Toggle buttons
        },
        events: '/api/appointments', // Load existing appointments
        selectable: true,
        editable: true,
        eventDurationEditable: true,
        height: 600,
        dateClick: function(info) {
            var now = new Date().getTime();
            // On double-click, open modal with the selected date/time.
           // if (now - lastClickTime < doubleClickThreshold) {
                // Get Date and time
                 var selectedDate = info.date.toISOString().split('T')[0];
                 var selectedTime = info.date.toTimeString().split(' ')[0];
                // Show modal                
                $('#appointments-date').val(selectedDate); // YYYY-MM-DD
                $('#appointments-time').val(selectedTime); // HH:MM:SS
                $('#appointmentModal').modal('show');
           // }
          //  lastClickTime = now;
        },

         // Handle event drag-and-drop
        eventDrop: function(info) {
            updateEvent(info.event);
        },
        
        // Handle event resizing
        eventResize: function(info) {
            updateEvent(info.event);
        },


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