/**
 * Appointments calendar (appointments/calendar).
 *
 * Settings come from <script type="application/json" id="booking-calendar-config">, built by
 * frontend\models\CalendarViewModel::clientConfig(). They only guide the page: the server re-checks
 * every booking and move.
 *
 * Needs FullCalendar, jQuery, Bootstrap and fetchCalendarEvents (Js/custom.js).
 */
(function () {
    'use strict';

    var configEl = document.getElementById('booking-calendar-config');
    var calendarEl = document.getElementById('calendar');
    if (!configEl || !calendarEl) {
        return;
    }
    var rules = JSON.parse(configEl.textContent);
    var avail = rules.availability;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    // Local date/time; toISOString() would give the UTC date, a day early just after midnight
    function localDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function localTime(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00'; }
    function minutesOf(hhmm) { var p = hhmm.split(':'); return p[0] * 60 + +p[1]; }

    // The block of hours (and so the facility) a session starting at date falls in, or null
    function blockAt(date) {
        if (!avail) {
            return null;
        }
        var day = localDate(date);
        var start = date.getHours() * 60 + date.getMinutes();
        return avail.blocks.find(function (b) {
            return b.daysOfWeek[0] === date.getDay()
                && start >= minutesOf(b.startTime) && start < minutesOf(b.endTime)
                && (!b.from || day >= b.from) && (!b.to || day <= b.to);
        }) || null;
    }

    // Why a session starting at date cannot be booked, or null when it can
    function slotProblem(date) {
        if (date.getTime() < Date.now() + rules.noticeMinutes * 60000) {
            return rules.noticeMinutes
                ? 'Please book at least ' + (rules.noticeMinutes === 60 ? '1 hour' : rules.noticeMinutes + ' minutes') + ' ahead.'
                : 'Please choose a time in the future.';
        }
        var block = blockAt(date);
        var duration = block ? block.duration : (avail ? avail.duration : 30);
        var closed = rules.blocked.find(function (b) {
            return date.getTime() < new Date(b.end).getTime() && date.getTime() + duration * 60000 > new Date(b.start).getTime();
        });
        if (closed) {
            return closed.reason;
        }
        if (!avail) {
            return null;
        }
        var start = date.getHours() * 60 + date.getMinutes();
        if (!block || start + duration > minutesOf(block.endTime)) {
            return 'That time is outside ' + (avail.doctor || 'the doctor') + "'s hours ("
                + (avail.summary || 'none set') + '). A ' + duration + '-minute session must fit within them.';
        }
        var facility = avail.facilities[block.facility];
        if (avail.patientBooking && facility && !facility.online) {
            return (avail.doctor || 'The doctor') + " doesn't take online bookings at " + facility.name
                + '. Please call ' + (facility.phone || 'the clinic') + ' to book.';
        }
        return null;
    }

    // "At Nairobi Hospital (address) · 30 minutes · KES 3,500" in the booking form
    function describePlace(date) {
        var block = blockAt(date);
        var facility = block && avail.facilities[block.facility];
        if (!facility) {
            $('#appointment-facility').addClass('d-none');
            return;
        }
        var text = 'At ' + facility.name + (facility.address ? ' (' + facility.address + ')' : '')
            + ' · ' + block.duration + ' minutes' + (facility.fee ? ' · ' + facility.fee : '');
        $('#appointment-facility').removeClass('d-none').text(text).css('border-left-color', facility.color);
    }

    // Several facilities: a colour key, and a filter to show one at a time. Events carry a
    // "fac-<id>" class and the others are hidden with a style rule, so refetching keeps the filter.
    var filterStyle = document.createElement('style');
    document.head.appendChild(filterStyle);
    var facilityIds = avail ? Object.keys(avail.facilities) : [];
    if (facilityIds.length > 1 && document.getElementById('facility-filter')) {
        var select = $('#facility-filter').removeClass('d-none').find('select');
        facilityIds.forEach(function (id) {
            $('<option>').val(id).text(avail.facilities[id].name).appendTo(select);
        });
        select.on('change', function () {
            var shown = this.value;
            filterStyle.textContent = shown ? facilityIds.filter(function (id) { return id !== shown; }).map(function (id) {
                return '#calendar .fac-' + id + ' { display: none; }';
            }).join('\n') : '';
        });
        var legend = $('#facility-legend').removeClass('d-none');
        facilityIds.forEach(function (id) {
            var f = avail.facilities[id];
            $('<span class="me-3 small text-nowrap">')
                .append($('<span class="d-inline-block rounded-circle me-1">').css({ width: '.7rem', height: '.7rem', background: f.color }))
                .append(document.createTextNode(f.name + ' · ' + f.duration + ' min' + (f.fee ? ' · ' + f.fee : '') + (f.online ? '' : ' · no online booking')))
                .appendTo(legend);
        });
    }

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        validRange: function () {
            var now = new Date();
            return {
                start: new Date(now.getFullYear(), now.getMonth(), now.getDate() - 5),
                end: new Date(now.getFullYear(), now.getMonth(), now.getDate() + rules.maxDaysAhead + 1)
            };
        },
        // Grey out closed hours and breaks; drag-and-drop stays inside them
        businessHours: avail ? avail.businessHours : false,
        eventConstraint: avail ? 'businessHours' : undefined,
        hiddenDays: avail ? avail.hiddenDays : [],
        slotMinTime: avail ? avail.slotMinTime : '00:00',
        slotMaxTime: avail ? avail.slotMaxTime : '24:00',
        slotDuration: avail ? avail.slotDuration : '00:30:00',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        // Existing appointments, holidays and time off as shaded background, and the facility of
        // each block of hours in its colour
        eventSources: [fetchCalendarEvents, rules.blocked, rules.facilityBlocks || []],
        selectable: !rules.readOnly,
        editable: !rules.readOnly,
        // Appointments keep the length they were booked with: they can be moved, not resized
        eventDurationEditable: false,
        height: 600,
        dateClick: function (info) {
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
            $('#appointments-date').val(localDate(info.date));
            $('#appointments-time').val(localTime(info.date));
            describePlace(info.date);
            $('#appointmentModal')
                .on('hidden.bs.modal', function () { location.reload(); })
                .modal('show');
        },
        eventDrop: function (info) {
            moveAppointment(info.event);
        },
        eventDidMount: function (info) {
            $(info.el).tooltip({
                title: info.event.extendedProps.description,
                placement: 'top',
                trigger: 'hover',
                container: 'body'
            });
        },
        eventClick: function (info) {
            // Only appointments have an id. Shaded blocks (doctor away, public holidays, other
            // patients' booked slots) have none: explain them instead of opening a broken link.
            if (!info.event.id) {
                var props = info.event.extendedProps;
                alert(props.reason || props.description || info.event.title || 'This time is not available.');
                return;
            }
            window.open('/appointments/view?id=' + encodeURIComponent(info.event.id), '_blank');
        }
    });
    calendar.render();

    function moveAppointment(event) {
        $.ajax({
            url: '/api/update-visit',
            type: 'POST',
            data: JSON.stringify({ id: event.id, date: localDate(event.start), time: localTime(event.start) }),
            contentType: 'application/json',
            success: function (response) {
                alert(response.message);
                if (response.status !== 'success') {
                    location.reload(); // put the event back where it was
                }
            },
            error: function (xhr) {
                alert('Error updating appointment: ' + xhr.responseText);
                location.reload();
            }
        });
    }

    // ---- Staff booking for a patient: look up, or register a walk-in --------------------------

    // " · 2 no-shows in the last year" for staff deciding whether to book; empty when there are none
    function noShowNote(patient) {
        var n = patient.noShows || 0;
        return n ? ' · ' + n + (n === 1 ? ' no-show' : ' no-shows') + ' in the last year' : '';
    }

    function choosePatient(patient) {
        $('#appointments-patient_id').val(patient.id);
        $('#patient-lookup-results').empty();
        $('#patient-selected').text('Booking for: ' + patient.name + noShowNote(patient));
        // F11: no consent on record -> staff ask and record it with the booking
        $('#patient-consent-given').prop('checked', false);
        $('input[name="patient-consent-reminders"]').prop('checked', false);
        $('#patient-consent').toggleClass('d-none', patient.consent !== false);
    }

    function checkedValue(name) {
        var input = $('input[name="' + name + '"]:checked');
        return input.length ? input.val() : '';
    }

    function listPatients(results, patients) {
        patients.forEach(function (patient) {
            var details = [patient.email, patient.phone].filter(Boolean).join(' · ') + noShowNote(patient)
                + (patient.consent === false ? ' · consent not recorded' : '');
            $('<button type="button" class="list-group-item list-group-item-action">')
                .text(patient.name + (details ? ' (' + details + ')' : ''))
                .on('click', function () { choosePatient(patient); $('#walk-in-form').addClass('d-none'); })
                .appendTo(results);
        });
    }

    // Find the patient by exact email or phone, then pick them from the matches
    function lookupPatient() {
        var results = $('#patient-lookup-results').empty();
        $('#appointments-patient_id').val('');
        $('#patient-selected').text('');

        $.getJSON('/api/find-patient', { q: $('#patient-lookup').val() })
            .done(function (response) {
                if (response.status !== 'success') {
                    results.append($('<div class="list-group-item text-danger">').text(response.message));
                    return;
                }
                if (response.patients.length === 1) {
                    choosePatient(response.patients[0]);
                    return;
                }
                // Several accounts share the number: let staff pick the right one
                listPatients(results, response.patients);
            })
            .fail(function () {
                results.append($('<div class="list-group-item text-danger">').text('Could not look up the patient.'));
            });
    }

    // Walk-ins without an account: register them (name + phone, email optional) and choose them
    function registerWalkIn(confirmNew) {
        var results = $('#patient-lookup-results').empty();
        $.ajax({
            url: '/api/register-patient',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                full_name: $('#walk-in-name').val(),
                phone: $('#walk-in-phone').val(),
                email: $('#walk-in-email').val(),
                consent: $('#walk-in-consent').is(':checked'), // ignored while consent recording is off
                reminders: checkedValue('walk-in-reminders'),
                confirm_new: confirmNew
            })
        }).done(function (response) {
            if (response.status === 'success') {
                choosePatient(response.patient);
                $('#walk-in-form').addClass('d-none');
            } else if (response.status === 'exists') {
                results.append($('<div class="list-group-item small">').text(response.message));
                listPatients(results, response.patients);
                $('<button type="button" class="list-group-item list-group-item-action text-primary">')
                    .text('Register as a new patient anyway')
                    .on('click', function () { registerWalkIn(true); })
                    .appendTo(results);
            } else {
                results.append($('<div class="list-group-item text-danger">').text(response.message));
            }
        }).fail(function () {
            results.append($('<div class="list-group-item text-danger">').text('Could not register the patient.'));
        });
    }

    $('#walk-in-toggle').on('click', function () { $('#walk-in-form').toggleClass('d-none'); });
    $('#walk-in-register').on('click', function () { registerWalkIn(false); });
    $('#patient-lookup-btn').on('click', lookupPatient);
    $('#patient-lookup').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault(); // look up instead of submitting the booking
            lookupPatient();
        }
    });

    // ---- Booking -------------------------------------------------------------------------------

    $('#appointmentForm').off('submit').on('submit', function (e) {
        e.preventDefault();
        var appointment = {
            date: $('#appointments-date').val(),
            time: $('#appointments-time').val(),
            brief: $('#appointments-symptoms_brief').val(),
            patient: $('#appointments-patient_id').val(),
            consultant: $('#appointments-consultant_id').val()
        };
        // The consent box only exists while consent recording is on
        if ($('#patient-consent').length && !$('#patient-consent').hasClass('d-none')) {
            if (!$('#patient-consent-given').is(':checked')) {
                alert("Confirm the patient's consent first.");
                return;
            }
            if (!checkedValue('patient-consent-reminders')) {
                alert('Ask whether the patient wants WhatsApp/SMS reminders.');
                return;
            }
            appointment.consent = true;
            appointment.reminders = checkedValue('patient-consent-reminders');
        }
        if (!appointment.consultant) {
            alert('Choose a consultant first.');
            return;
        }
        if (rules.booksForPatients && !appointment.patient) {
            alert('Find and choose the patient first.');
            return;
        }
        $.ajax({
            url: '/api/visit',
            type: 'POST',
            data: JSON.stringify(appointment),
            contentType: 'application/json',
            success: function (response) {
                if (response.status === 'success') {
                    alert(response.message);
                    $('#appointmentModal').modal('hide');
                    calendar.refetchEvents();
                } else {
                    alert(response.message || 'Error saving appointment.');
                }
            },
            error: function () {
                alert('Error saving appointment.');
            }
        });
    });
})();
