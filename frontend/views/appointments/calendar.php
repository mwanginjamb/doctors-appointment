<?php

use frontend\models\Consultant;
use yii\web\View;
use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/**
 * @var yii\web\View $this
 */
$role = Yii::$app->user->identity->role;
$title = 'Appointments Calendar';
if ($role == 'client') {
    $title = 'Consultantations Appointments for ' . ucwords(Yii::$app->user->identity->full_name);
    $ConsultantSession = Yii::$app->session->get('booking_session_' . Yii::$app->user->id);
    $consultant = Consultant::findOne(['id' => $ConsultantSession]);
    // Yii::$app->utility->printrr($consultant);
} else if ($role == 'consultant') {
    $consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
    $title = 'Consultantations Schedule for ' . ucwords($consultant->names);
}

// Admins open a consultant's calendar from the appointments board; it is read-only for them
$readOnly = !in_array($role, ['client', 'consultant'], true);
if ($readOnly) {
    $consultant = $consultant ?? null;
    if ($consultant === null && \frontend\components\AdminAccessControl::isAdmin()) {
        throw new \yii\web\NotFoundHttpException('Open a consultant from the appointments board to see their calendar.');
    }
    $title = 'Schedule for ' . ($consultant->names ?? 'consultant');
}

$this->title = $title;
$isConsultant = json_encode($role === 'consultant');

// What the calendar may offer. The server re-checks every booking (Appointments::validateSlot).
$params = Yii::$app->params;
$availability = null;
if (!empty($consultant)) {
    $ranges = [];
    foreach ($consultant->getWeek() as $isoDay => $dayRanges) {
        foreach ($dayRanges as [$start, $end]) {
            // FullCalendar counts days from Sunday = 0; ISO Sunday is 7
            $ranges[] = ['daysOfWeek' => [$isoDay % 7], 'startTime' => substr($start, 0, 5), 'endTime' => substr($end, 0, 5)];
        }
    }
    $openDays = array_unique(array_map(function ($r) {
        return $r['daysOfWeek'][0];
    }, $ranges));
    $availability = [
        'businessHours' => $ranges,
        // Hide closed days, but never all seven (FullCalendar cannot render that)
        'hiddenDays' => $openDays ? array_values(array_diff(range(0, 6), $openDays)) : [],
        'slotMinTime' => $ranges ? min(array_column($ranges, 'startTime')) : '08:00',
        'slotMaxTime' => $ranges ? max(array_column($ranges, 'endTime')) : '17:00',
        'duration' => (new \frontend\models\Appointments(['consultant_id' => $consultant->user_id]))->getDurationMinutes(),
        'doctor' => $consultant->names,
        'summary' => $consultant->getHoursSummary(),
    ];
}
// Closed periods inside the booking window, shown as shaded background and refused on click.
// Full local date-times ("YYYY-MM-DDTHH:MM:SS", no zone) so browsers read them as local time.
$windowStart = date('Y-m-d');
$windowEnd = date('Y-m-d', strtotime('+' . (int) ($params['booking.maxDaysAhead'] ?? 90) . ' days'));
$blocked = [];
foreach (\frontend\models\PublicHoliday::between($windowStart, $windowEnd) as $holiday) {
    $blocked[] = [
        'start' => $holiday->date . 'T00:00:00',
        'end' => date('Y-m-d', strtotime($holiday->date . ' +1 day')) . 'T00:00:00',
        'title' => 'Public holiday: ' . $holiday->name,
        'reason' => $holiday->name . ' is a public holiday; the clinic is closed.',
    ];
}
if (!empty($consultant)) {
    // Time off reasons are private: patients only see that the doctor is away
    foreach (\frontend\models\ConsultantTimeOff::overlapping($consultant->id, $windowStart . ' 00:00:00', $windowEnd . ' 23:59:59') as $away) {
        $blocked[] = [
            'start' => str_replace(' ', 'T', $away->start_at),
            'end' => str_replace(' ', 'T', $away->end_at),
            'title' => $role === 'consultant' ? 'Time off' . ($away->reason ? ': ' . $away->reason : '') : 'Doctor away',
            'reason' => ($consultant->names ?: 'The doctor') . ' is away ' . $away->getLabel() . '.',
        ];
    }
}
foreach ($blocked as &$period) {
    // overlap: false keeps appointments from being dragged onto it
    $period += ['display' => 'background', 'color' => '#f1aeb5', 'overlap' => false];
}
unset($period);

$bookingRules = json_encode([
    'availability' => $availability,
    'blocked' => $blocked,
    // Consultants book walk-ins into their own calendar without notice
    'noticeMinutes' => $role === 'consultant' ? 0 : (int) ($params['booking.minNoticeMinutes'] ?? 0),
    'maxDaysAhead' => (int) ($params['booking.maxDaysAhead'] ?? 90),
    'readOnly' => $readOnly,
]);

?>
<div class="container mt-4">
    <div class="calendar-header text-center py-3">
        <h1 class="fw-bold display-5 text-primary"><?= Html::encode(ucwords($title)) ?> </h1>
        <p class="lead text-muted">
            <?= $readOnly
                ? 'View only. Click an appointment to open it; cancel it from there or from the appointments board.'
                : 'Please click on any available time slot to enter your appointment.' ?>
        </p>
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

                <?php if ($role === 'consultant'): ?>
                    <!-- A consultant books for a registered patient, found by exact email or phone -->
                    <div class="mb-3">
                        <label class="form-label" for="patient-lookup"><?= Yii::t('app', 'Patient') ?></label>
                        <div class="input-group">
                            <input type="text" id="patient-lookup" class="form-control" autocomplete="off"
                                placeholder="<?= Yii::t('app', "Patient's email or phone number") ?>">
                            <button type="button" class="btn btn-outline-primary" id="patient-lookup-btn"><?= Yii::t('app', 'Find') ?></button>
                        </div>
                        <div id="patient-lookup-results" class="list-group mt-2"></div>
                        <div id="patient-selected" class="form-text fw-semibold"></div>
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


<?php
$script = <<<JS

    var calendarEl = document.getElementById('calendar');
    var rules = {$bookingRules};
    var avail = rules.availability;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    // Local date/time; toISOString() would give the UTC date, a day early just after midnight
    function localDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function localTime(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00'; }
    function minutesOf(hhmm) { var p = hhmm.split(':'); return p[0] * 60 + +p[1]; }

    // Why a session starting at date cannot be booked, or null when it can
    function slotProblem(date) {
        if (date.getTime() < Date.now() + rules.noticeMinutes * 60000) {
            return rules.noticeMinutes
                ? 'Please book at least ' + (rules.noticeMinutes === 60 ? '1 hour' : rules.noticeMinutes + ' minutes') + ' ahead.'
                : 'Please choose a time in the future.';
        }
        var sessionMs = (avail ? avail.duration : 30) * 60000;
        var closed = rules.blocked.find(function (b) {
            return date.getTime() < new Date(b.end).getTime() && date.getTime() + sessionMs > new Date(b.start).getTime();
        });
        if (closed) {
            return closed.reason;
        }
        if (!avail) {
            return null;
        }
        var start = date.getHours() * 60 + date.getMinutes();
        var fits = avail.businessHours.some(function (r) {
            return r.daysOfWeek[0] === date.getDay()
                && start >= minutesOf(r.startTime)
                && start + avail.duration <= minutesOf(r.endTime);
        });
        return fits ? null : 'That time is outside ' + (avail.doctor || "the doctor") + "'s hours ("
            + (avail.summary || 'none set') + '). A ' + avail.duration + '-minute session must fit within them.';
    }

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek', // Day view - Default View
        validRange: function() {
            let nowDate = new Date(); // Get current date
            return {
                start: new Date(nowDate.getFullYear(), nowDate.getMonth(), nowDate.getDate() - 5),
                end: new Date(nowDate.getFullYear(), nowDate.getMonth(), nowDate.getDate() + rules.maxDaysAhead + 1)
            };
        },
        // Grey out closed hours and breaks; drag-and-drop stays inside them
        businessHours: avail ? avail.businessHours : false,
        eventConstraint: avail ? 'businessHours' : undefined,
        hiddenDays: avail ? avail.hiddenDays : [],
        slotMinTime: avail ? avail.slotMinTime : '00:00',
        slotMaxTime: avail ? avail.slotMaxTime : '24:00',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' // Toggle buttons
        },
        // Existing appointments, plus holidays and time off as shaded background
        eventSources: [fetchCalendarEvents, rules.blocked],
        selectable: !rules.readOnly,
        editable: !rules.readOnly,
        eventDurationEditable: !rules.readOnly,
        height: 600,
        dateClick: function(info) {
            // A day in month view has no time yet: open that day to pick one
            if (info.allDay) {
                calendar.changeView('timeGridDay', info.date);
                return;
            }
            if (rules.readOnly) {
                return;
            }
            var problem = slotProblem(info.date);
            if (problem) {
                alert(problem);
                return;
            }
            $('#appointments-date').val(localDate(info.date)); // YYYY-MM-DD
            $('#appointments-time').val(localTime(info.date)); // HH:MM:SS
            $('#appointmentModal')
                .on('hidden.bs.modal', () => location.reload())
                .modal('show');
        },

         // Handle event drag-and-drop
        eventDrop: function(info) {
            updateEvent(info.event);
        },
        
        // Handle event resizing
        eventResize: function(info) {
            updateEvent(info.event);
        },
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
            date: localDate(event.start), // YYYY-MM-DD
            time: localTime(event.start) // HH:MM:SS
        };

        $.ajax({
            url: '/api/update-visit',
            type: 'POST',
            data: JSON.stringify(eventData),
            contentType: 'application/json',
            success: function(response) {
                alert(response.message);
                if (response.status !== 'success') {
                    location.reload(); // put the event back where it was
                }
            },
            error: function(xhr) {
                console.log(xhr);
                alert('Error updating appointment: ' + xhr.responseText);
                location.reload(); // Reload calendar if update fails
            }
        });
    }

    var isConsultant = {$isConsultant};

    // Consultants: find the patient by exact email or phone, then pick them from the matches
    function choosePatient(patient) {
        $('#appointments-patient_id').val(patient.id);
        $('#patient-lookup-results').empty();
        $('#patient-selected').text('Booking for: ' + patient.name);
    }

    function lookupPatient() {
        var results = $('#patient-lookup-results').empty();
        $('#appointments-patient_id').val('');
        $('#patient-selected').text('');

        $.getJSON('/api/find-patient', { q: $('#patient-lookup').val() })
            .done(function(response) {
                if (response.status !== 'success') {
                    results.append($('<div class="list-group-item text-danger">').text(response.message));
                    return;
                }
                if (response.patients.length === 1) {
                    choosePatient(response.patients[0]);
                    return;
                }
                // Several accounts share the number: let the consultant pick the right one
                response.patients.forEach(function(patient) {
                    var details = [patient.email, patient.phone].filter(Boolean).join(' · ');
                    $('<button type="button" class="list-group-item list-group-item-action">')
                        .text(patient.name + (details ? ' (' + details + ')' : ''))
                        .on('click', function() { choosePatient(patient); })
                        .appendTo(results);
                });
            })
            .fail(function() {
                results.append($('<div class="list-group-item text-danger">').text('Could not look up the patient.'));
            });
    }

    $('#patient-lookup-btn').on('click', lookupPatient);
    $('#patient-lookup').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault(); // look up instead of submitting the booking
            lookupPatient();
        }
    });

    // Handle form submission
    $('#appointmentForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        let appointmentData = {
            date: $('#appointments-date').val(),
            time: $('#appointments-time').val(),
            brief: $('#appointments-symptoms_brief').val(),
            patient: $('#appointments-patient_id').val(),
            consultant: $('#appointments-consultant_id').val(),
        };

        if(!appointmentData.consultant){
            console.error(`consultant Needed ...`); return;
        }
        if (isConsultant && !appointmentData.patient) {
            alert('Find and choose the patient first.');
            return;
        }
        $.ajax({
            url: '/api/visit',
            type: 'POST',
            data: JSON.stringify(appointmentData),
            contentType: 'application/json',
            success: function(response) {
                if(response.status === 'success'){
                    alert(response.message);
                    console.log(response);
                    $('#appointmentModal').modal('hide');
                    calendar.refetchEvents(); // Reload events from API
                    return;
                } else {
                     console.log(response);
                    alert(response.message || response.status + 'Error saving appointment.');
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