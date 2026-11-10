/**
 * Picker for free slots, used by the booking-link page (book/index) and the patient's self-service
 * page (manage/index). Only the green slots can be chosen; the grid behind them is greyed out.
 *
 * Settings come from <script type="application/json" id="slot-picker-config">:
 *   events         [{start, end, title, extendedProps: {date, time, label}}]
 *   from, end      first day shown, and the day after the last (exclusive)
 *   days           number of days offered, all shown at once
 *   weekly         page a week at a time instead (prev/next) when true
 *   slotDuration, slotMinTime, slotMaxTime
 *   dateInput, timeInput   ids of the hidden inputs to fill
 *   label, labelPrefix     id of the element showing the choice, and its prefix
 *   button                 id of the submit button to enable
 *   scrollTo               id of an element to scroll to after choosing (optional)
 *
 * Needs FullCalendar.
 */
(function () {
    'use strict';

    var configEl = document.getElementById('slot-picker-config');
    var calendarEl = document.getElementById('slot-picker');
    if (!configEl || !calendarEl) {
        return;
    }
    var cfg = JSON.parse(configEl.textContent);
    var mobile = window.innerWidth < 768;
    var short = !cfg.weekly;

    new FullCalendar.Calendar(calendarEl, {
        // A booking link's few days are shown whole; the self-service range a week at a time
        initialView: mobile ? (short ? 'listRange' : 'listWeek') : (short ? 'gridRange' : 'timeGridWeek'),
        initialDate: cfg.from,
        validRange: { start: cfg.from, end: cfg.end },
        views: {
            gridRange: { type: 'timeGrid', duration: { days: cfg.days }, buttonText: 'calendar' },
            listRange: { type: 'list', duration: { days: cfg.days }, buttonText: 'list' }
        },
        headerToolbar: short
            ? { left: '', center: 'title', right: 'gridRange,listRange' }
            : { left: 'prev,next', center: 'title', right: 'timeGridWeek,listWeek' },
        allDaySlot: false,
        slotDuration: cfg.slotDuration,
        slotMinTime: cfg.slotMinTime,
        slotMaxTime: cfg.slotMaxTime,
        // Only the offered slots are open; everything else in the grid is greyed out (page CSS)
        businessHours: false,
        selectable: false,
        editable: false,
        height: 'auto',
        events: cfg.events,
        eventColor: '#198754',
        eventClick: function (info) {
            var p = info.event.extendedProps;
            document.getElementById(cfg.dateInput).value = p.date;
            document.getElementById(cfg.timeInput).value = p.time;
            document.getElementById(cfg.label).textContent = cfg.labelPrefix + p.label;
            document.getElementById(cfg.button).disabled = false;
            if (cfg.scrollTo) {
                document.getElementById(cfg.scrollTo).scrollIntoView({ behavior: 'smooth' });
            }
        }
    }).render();
})();
