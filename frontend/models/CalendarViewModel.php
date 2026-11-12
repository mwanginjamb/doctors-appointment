<?php

namespace frontend\models;

use frontend\components\FrontDesk;
use Yii;
use yii\base\BaseObject;

/**
 * Everything the appointments calendar page (appointments/calendar) shows and allows, worked out
 * once from the viewer and the consultant: its title, whether the viewer books for patients or for
 * themselves or only looks, and the settings for the calendar script (Js/booking-calendar.js).
 *
 * Which calendar it is depends on the account type; what may be done in it, on permissions. The
 * server re-checks every booking (BookingService), so these settings only guide the page.
 */
class CalendarViewModel extends BaseObject
{
    /** @var \common\models\User the signed-in viewer */
    public $viewer;

    /** @var Consultant|null the consultant whose calendar this is */
    public $consultant;

    /** @var string */
    public $title = 'Appointments Calendar';

    /** @var bool staff booking for a patient they look up (or register as a walk-in) */
    public $booksForPatients = false;

    /** @var bool nothing can be booked or moved here */
    public $readOnly = false;

    /** @var bool the consultant no longer takes bookings */
    public $deactivated = false;

    /** @var int|null front desk: the facility they book at; their hours elsewhere are not offered */
    public $facilityId;

    public function init()
    {
        parent::init();
        $viewer = $this->viewer;
        if ($viewer->isFrontDeskAccount() || $viewer->isFacilityAdminAccount()) {
            $this->facilityId = \common\rbac\AssignedFacilityRule::facilityIdFor($viewer->id);
        }

        if ($viewer->isConsultantAccount()) {
            $this->consultant = Consultant::findOne(['user_id' => $viewer->id]);
        }

        if ($viewer->isPatientAccount()) {
            $this->title = 'Consultantations Appointments for ' . ucwords((string) $viewer->full_name);
        } elseif ($viewer->isConsultantAccount() && $this->consultant) {
            $this->title = 'Consultantations Schedule for ' . ucwords((string) $this->consultant->names);
        } elseif ($viewer->isFrontDeskAccount() && $this->consultant) {
            $facility = $this->facilityId ? Facility::findOne($this->facilityId) : null;
            $this->title = 'Front desk: ' . $this->consultant->names . ($facility ? ' at ' . $facility->name : '');
        }

        // The consultant themselves, or their front desk
        $this->booksForPatients = !$viewer->isPatientAccount() && $this->consultant && $this->consultant->user_id
            && FrontDesk::canBookFor($this->consultant->user_id);
        // Patients book for themselves; staff who may not book here (admins) only look
        $this->deactivated = $this->consultant !== null && !$this->consultant->isTakingBookings();
        $this->readOnly = (!$viewer->isPatientAccount() && !$this->booksForPatients) || $this->deactivated;

        if ($this->readOnly) {
            $this->title = 'Schedule for ' . ($this->consultant->names ?? 'consultant');
        }
    }

    /**
     * Staff who may only look at a calendar need to say whose: there is no "own" calendar for them.
     */
    public function needsConsultant()
    {
        return $this->readOnly && $this->consultant === null && FrontDesk::isStaff();
    }

    /**
     * Settings for Js/booking-calendar.js.
     */
    public function clientConfig()
    {
        $params = Yii::$app->params;

        return [
            'availability' => $this->availability(),
            'blocked' => $this->blockedPeriods(),
            'facilityBlocks' => $this->facilityBlocks(),
            // Staff book walk-ins into the consultant's calendar without notice
            'noticeMinutes' => $this->booksForPatients ? 0 : (int) ($params['booking.minNoticeMinutes'] ?? 0),
            'maxDaysAhead' => (int) ($params['booking.maxDaysAhead'] ?? 90),
            'readOnly' => $this->readOnly,
            'booksForPatients' => (bool) $this->booksForPatients,
        ];
    }

    /**
     * Opening hours as FullCalendar business hours, the facility of each block of hours, and the
     * grid settings that follow from them.
     *
     * Each block carries its facility's session length, so the page knows how long a session
     * starting there is; the grid steps by the largest length that divides them all.
     *
     * @return array|null null when there is no consultant
     */
    public function availability()
    {
        $consultant = $this->consultant;
        if ($consultant === null) {
            return null;
        }

        $blocks = [];
        $workDays = [];
        $workTimes = [];
        foreach ($consultant->getBlocks() as $isoDay => $dayRanges) {
            foreach ($dayRanges as [$start, $end, $facilityId, $rotation]) {
                $engagement = $consultant->getEngagement($facilityId);
                if ($engagement === null || !$engagement->facility->isActive()) {
                    continue;
                }
                // Days and times the consultant works anywhere: the calendar shows them all, so front
                // desk also sees the days they are elsewhere (their appointments there show as busy)
                $workDays[] = $isoDay % 7;
                $workTimes[] = ['startTime' => substr($start, 0, 5), 'endTime' => substr($end, 0, 5)];
                // Front desk books only at their facility: the consultant's hours elsewhere are closed to them
                if ($this->facilityId !== null && (int) $facilityId !== $this->facilityId) {
                    continue;
                }
                // FullCalendar counts days from Sunday = 0; ISO Sunday is 7. The block runs inside
                // both the engagement's dates and its own (F8 phase 3), in its week of the rotation.
                $blocks[] = [
                    'daysOfWeek' => [$isoDay % 7],
                    'startTime' => substr($start, 0, 5),
                    'endTime' => substr($end, 0, 5),
                    'facility' => (int) $facilityId,
                    'duration' => (int) $engagement->session_minutes,
                    'from' => max((string) $engagement->from_date, (string) ($rotation['from'] ?? '')) ?: null,
                    'to' => self::earliest($engagement->to_date, $rotation['to'] ?? null),
                    'weeks' => $rotation['weeks'] ?? null,
                ];
            }
        }
        // One-off sessions (F8 phase 3), upcoming ones
        $sessions = [];
        foreach ($consultant->sessions as $session) {
            $engagement = $consultant->getEngagement($session->facility_id);
            if ($session->date < date('Y-m-d') || $engagement === null) {
                continue;
            }
            // Shown wherever it is; bookable here only at front desk's own facility
            $workDays[] = (int) date('w', strtotime($session->date));
            $workTimes[] = ['startTime' => substr($session->start_time, 0, 5), 'endTime' => substr($session->end_time, 0, 5)];
            if ($this->facilityId !== null && (int) $session->facility_id !== $this->facilityId) {
                continue;
            }
            $sessions[] = [
                'date' => $session->date,
                'startTime' => substr($session->start_time, 0, 5),
                'endTime' => substr($session->end_time, 0, 5),
                'facility' => (int) $session->facility_id,
                'duration' => (int) $engagement->session_minutes,
                'note' => $session->note,
            ];
        }
        $ranges = array_map(function ($b) {
            return ['daysOfWeek' => $b['daysOfWeek'], 'startTime' => $b['startTime'], 'endTime' => $b['endTime']];
        }, $blocks);
        $openDays = array_unique(array_merge($workDays, array_map(function ($s) {
            return (int) date('w', strtotime($s['date']));
        }, $sessions)));
        $times = array_merge($workTimes, $sessions);

        $facilities = [];
        foreach ($consultant->engagements as $engagement) {
            $facility = $engagement->facility;
            $facilities[$facility->id] = [
                'name' => $facility->name,
                'color' => $facility->getColor(),
                'address' => $facility->address ? preg_replace('/\s*\R\s*/', ', ', $facility->address) : null,
                'phone' => $facility->phone,
                'duration' => (int) $engagement->session_minutes,
                'fee' => $engagement->getFeeLabel(),
                'online' => $engagement->isBookingOpen(),
            ];
        }
        $durations = array_unique(array_merge(array_column($blocks, 'duration'), array_column($sessions, 'duration'))) ?: [$consultant->getSessionMinutes()];
        $grid = array_reduce($durations, function ($gcd, $m) {
            for ($a = $gcd, $b = $m; $b; [$a, $b] = [$b, $a % $b]);
            return $a;
        }, 0);
        $minutes = max(5, $grid);

        return [
            'businessHours' => $ranges,
            'blocks' => $blocks,
            'sessions' => $sessions,
            // Week A of alternate-week hours starts on this Monday
            'rotationWeekA' => $consultant->rotation_week_a,
            // Sessions are outside the weekly business hours, so appointments may be dragged to them
            'constrainToHours' => !$sessions,
            'facilities' => $facilities,
            // Hide closed days, but never all seven (FullCalendar cannot render that)
            'hiddenDays' => $openDays ? array_values(array_diff(range(0, 6), $openDays)) : [],
            'slotMinTime' => $times ? min(array_column($times, 'startTime')) : '08:00',
            'slotMaxTime' => $times ? max(array_column($times, 'endTime')) : '17:00',
            // Length of a session outside any block (only used to explain why it cannot be booked)
            'duration' => count($durations) === 1 ? (int) reset($durations) : $consultant->getSessionMinutes(),
            'slotDuration' => sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60),
            'doctor' => $consultant->names,
            'summary' => $consultant->getHoursSummary(),
            // Patients book themselves: refuse facilities that take no online bookings
            'patientBooking' => $this->viewer->isPatientAccount(),
        ];
    }

    /** The earlier of two optional dates (Y-m-d), or null when neither is set. */
    private static function earliest($a, $b)
    {
        $dates = array_filter([$a, $b]);

        return $dates ? min($dates) : null;
    }

    /**
     * Each block of hours as a background event in its facility's colour, so the consultant sees
     * where they are when: recurring weekly blocks when there are several facilities, and always
     * alternate-week blocks (on their weeks only) and one-off sessions (F8 phase 3).
     */
    public function facilityBlocks()
    {
        $availability = $this->availability();
        if ($availability === null) {
            return [];
        }
        $several = count($availability['facilities']) >= 2;

        $events = [];
        foreach ($availability['sessions'] as $session) {
            $facility = $availability['facilities'][$session['facility']];
            $events[] = [
                'start' => $session['date'] . 'T' . $session['startTime'] . ':00',
                'end' => $session['date'] . 'T' . $session['endTime'] . ':00',
                'display' => 'background',
                'color' => $facility['color'],
                'title' => 'Session: ' . $facility['name'] . ($session['note'] ? ' (' . $session['note'] . ')' : ''),
                'classNames' => ['facility-block', 'session-block', 'fac-' . $session['facility']],
                'extendedProps' => ['facilityId' => $session['facility']],
            ];
        }

        foreach ($availability['blocks'] as $block) {
            if ($block['weeks']) {
                $events = array_merge($events, $this->rotationEvents($block, $availability['facilities'][$block['facility']]));
                continue;
            }
            if (!$several) {
                continue;
            }
            $facility = $availability['facilities'][$block['facility']];
            $event = [
                'daysOfWeek' => $block['daysOfWeek'],
                'startTime' => $block['startTime'],
                'endTime' => $block['endTime'],
                'display' => 'background',
                'color' => $facility['color'],
                'title' => $facility['name'],
                'classNames' => ['facility-block', 'fac-' . $block['facility']],
                'extendedProps' => ['facilityId' => $block['facility']],
            ];
            // An engagement with dates only shows between them (endRecur is exclusive)
            if ($block['from']) {
                $event['startRecur'] = $block['from'];
            }
            if ($block['to']) {
                $event['endRecur'] = date('Y-m-d', strtotime($block['to'] . ' +1 day'));
            }
            $events[] = $event;
        }

        return $events;
    }

    /**
     * An alternate-week block as dated background events, on the days of its weeks only, from a
     * week back to the end of the booking window.
     */
    private function rotationEvents(array $block, array $facility)
    {
        $consultant = $this->consultant;
        $isoDay = $block['daysOfWeek'][0] ?: 7;
        $events = [];
        $end = strtotime('+' . (int) (Yii::$app->params['booking.maxDaysAhead'] ?? 90) . ' days');
        for ($ts = strtotime('-7 days'); $ts <= $end; $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            if ((int) date('N', $ts) !== $isoDay || ($block['from'] && $date < $block['from']) || ($block['to'] && $date > $block['to'])
                || $consultant->weekLetter($date) !== $block['weeks']) {
                continue;
            }
            $events[] = [
                'start' => $date . 'T' . $block['startTime'] . ':00',
                'end' => $date . 'T' . $block['endTime'] . ':00',
                'display' => 'background',
                'color' => $facility['color'],
                'title' => $facility['name'] . ' (week ' . $block['weeks'] . ')',
                'classNames' => ['facility-block', 'fac-' . $block['facility']],
                'extendedProps' => ['facilityId' => $block['facility']],
            ];
        }

        return $events;
    }

    /**
     * Public holidays and the consultant's time off inside the booking window, as shaded background
     * events. Full local date-times ("YYYY-MM-DDTHH:MM:SS", no zone) so browsers read them as local.
     */
    public function blockedPeriods()
    {
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+' . (int) (Yii::$app->params['booking.maxDaysAhead'] ?? 90) . ' days'));
        $blocked = [];

        foreach (PublicHoliday::between($from, $to) as $holiday) {
            $blocked[] = [
                'start' => $holiday->date . 'T00:00:00',
                'end' => date('Y-m-d', strtotime($holiday->date . ' +1 day')) . 'T00:00:00',
                'title' => 'Public holiday: ' . $holiday->name,
                'reason' => $holiday->name . ' is a public holiday; the clinic is closed.',
            ];
        }

        $consultant = $this->consultant;
        if ($consultant !== null) {
            $blocked = array_merge($blocked, $this->closurePeriods($from, $to), $this->travelPeriods($from, $to), $this->externalPeriods($from, $to));
            $own = (int) $consultant->user_id === (int) $this->viewer->id;
            foreach (ConsultantTimeOff::overlapping($consultant->id, $from . ' 00:00:00', $to . ' 23:59:59') as $away) {
                $blocked[] = [
                    'start' => str_replace(' ', 'T', $away->start_at),
                    'end' => str_replace(' ', 'T', $away->end_at),
                    // The reason is private: only the consultant themselves sees it
                    'title' => $own ? 'Time off' . ($away->reason ? ': ' . $away->reason : '') : 'Doctor away',
                    'reason' => ($consultant->names ?: 'The doctor') . ' is away ' . $away->getLabel() . '.',
                ];
            }
        }

        // overlap: false keeps appointments from being dragged onto them
        return array_map(function ($period) {
            return $period + ['display' => 'background', 'color' => '#f1aeb5', 'overlap' => false];
        }, $blocked);
    }

    /**
     * Days a facility the consultant works at is closed (F8 phase 2), over the consultant's hours
     * there that day, so their hours elsewhere that day stay bookable.
     */
    private function closurePeriods($from, $to)
    {
        $consultant = $this->consultant;
        $facilityIds = array_map(function ($e) {
            return (int) $e->facility_id;
        }, $consultant->engagements);
        $periods = [];
        foreach (FacilityClosure::between($facilityIds, $from, $to) as $closure) {
            $engagement = $consultant->getEngagement($closure->facility_id);
            if (($engagement->from_date && $closure->date < $engagement->from_date) || ($engagement->to_date && $closure->date > $engagement->to_date)) {
                continue;
            }
            foreach ($consultant->blocksOn($closure->date) as [$open, $close, $facilityId]) {
                if ($facilityId !== (int) $closure->facility_id) {
                    continue;
                }
                $name = $engagement->facility->name ?? 'The facility';
                $periods[] = [
                    'start' => $closure->date . 'T' . $open,
                    'end' => $closure->date . 'T' . $close,
                    'title' => $name . ' closed: ' . $closure->reason,
                    'reason' => $name . ' is closed on ' . date('l j M', strtotime($closure->date)) . ' (' . $closure->reason . ').',
                ];
            }
        }

        return $periods;
    }

    /**
     * The consultant's hours at places that do not use the app (F8 phase 3), as "Busy at <place>".
     */
    private function externalPeriods($from, $to)
    {
        $consultant = $this->consultant;
        if (!$consultant->externalBlocks) {
            return [];
        }
        $periods = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            foreach ($consultant->externalOn($date) as [$busyFrom, $busyTo, $place]) {
                $periods[] = [
                    'start' => $date . 'T' . $busyFrom,
                    'end' => $date . 'T' . $busyTo,
                    'title' => 'Busy at ' . $place,
                    'reason' => ($consultant->names ?: 'The doctor') . ' is busy at ' . $place . ' from ' . substr($busyFrom, 0, 5) . ' to ' . substr($busyTo, 0, 5) . '.',
                    'color' => '#ced4da',
                ];
            }
        }

        return $periods;
    }

    /**
     * The consultant's travel buffer before each block at another facility (F8 phase 2): a session
     * ending inside it would leave too little time to get there.
     */
    private function travelPeriods($from, $to)
    {
        $consultant = $this->consultant;
        $buffer = (int) $consultant->travel_buffer_minutes;
        if ($buffer <= 0 || count($consultant->engagements) < 2) {
            return [];
        }
        $periods = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $ranges = $consultant->getRangesFor($date);
            foreach ($ranges as [$open, $close, $facilityId]) {
                $arrive = strtotime($date . ' ' . $open);
                foreach ($ranges as [$prevOpen, $prevClose, $prevFacility]) {
                    $leave = strtotime($date . ' ' . $prevClose);
                    if ($prevFacility === $facilityId || $leave > $arrive || $leave <= $arrive - $buffer * 60) {
                        continue;
                    }
                    $name = $consultant->getEngagement($facilityId)->facility->name ?? 'another facility';
                    $periods[] = [
                        'start' => date('Y-m-d\TH:i:s', max(strtotime($date . ' ' . $prevOpen), $arrive - $buffer * 60)),
                        'end' => date('Y-m-d\TH:i:s', $leave),
                        'title' => 'Travel to ' . $name,
                        'reason' => ($consultant->names ?: 'The doctor') . ' leaves for ' . $name . ' (from ' . substr($open, 0, 5) . ') and needs '
                            . $buffer . ' minutes to get there. Please choose an earlier time.',
                    ];
                }
            }
        }

        return $periods;
    }
}
