<?php

use yii\web\View;
use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/**
 * @var yii\web\View $this
 */
$title = 'Consultantations Schedule';





?>

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

                <?= $form->field($model, 'patient_id')->hiddenInput(['value' => 1])->label(false) ?>
                <?= $form->field($model, 'date')->textInput(['type' => 'date']) ?>
                <?= $form->field($model, 'time')->textInput(['type' => 'time']) ?>
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
        initialView: 'timeGridDay', // Day view - Default View
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
            if (now - lastClickTime < doubleClickThreshold) {
                // Get Date and time
                 var selectedDate = info.date.toISOString().split('T')[0];
                 var selectedTime = info.date.toTimeString().split(' ')[0];
                // Show modal                
                $('#appointments-date').val(selectedDate); // YYYY-MM-DD
                $('#appointments-time').val(selectedTime); // HH:MM:SS
                $('#appointmentModal').modal('show');
            }
            lastClickTime = now;
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
        console.table(event);
        exit;
    }

    // Handle form submission
    $('#appointmentForm').on('submit', function(e) {
        e.preventDefault();
        let appointmentData = {
            date: $('#appointments-date').val(),
            time: $('#appointments-time').val(),
            brief: $('#appointments-symptoms_brief').val(),
            patient_name: $('#appointments-patient_id').val()
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