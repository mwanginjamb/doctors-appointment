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

    public function init()
    {
        parent::init();
        $viewer = $this->viewer;

        if ($viewer->isConsultantAccount()) {
            $this->consultant = Consultant::findOne(['user_id' => $viewer->id]);
        }

        if ($viewer->isPatientAccount()) {
            $this->title = 'Consultantations Appointments for ' . ucwords((string) $viewer->full_name);
        } elseif ($viewer->isConsultantAccount() && $this->consultant) {
            $this->title = 'Consultantations Schedule for ' . ucwords((string) $this->consultant->names);
        } elseif ($viewer->isFrontDeskAccount() && $this->consultant) {
            $this->title = 'Front desk: ' . $this->consultant->names;
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
            // Staff book walk-ins into the consultant's calendar without notice
            'noticeMinutes' => $this->booksForPatients ? 0 : (int) ($params['booking.minNoticeMinutes'] ?? 0),
            'maxDaysAhead' => (int) ($params['booking.maxDaysAhead'] ?? 90),
            'readOnly' => $this->readOnly,
            'booksForPatients' => (bool) $this->booksForPatients,
        ];
    }

    /**
     * Opening hours as FullCalendar business hours, and the grid settings that follow from them.
     *
     * @return array|null null when there is no consultant
     */
    public function availability()
    {
        $consultant = $this->consultant;
        if ($consultant === null) {
            return null;
        }

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
        $minutes = $consultant->getSessionMinutes();

        return [
            'businessHours' => $ranges,
            // Hide closed days, but never all seven (FullCalendar cannot render that)
            'hiddenDays' => $openDays ? array_values(array_diff(range(0, 6), $openDays)) : [],
            'slotMinTime' => $ranges ? min(array_column($ranges, 'startTime')) : '08:00',
            'slotMaxTime' => $ranges ? max(array_column($ranges, 'endTime')) : '17:00',
            // Length of new bookings; the grid shows slots of this length
            'duration' => $minutes,
            'slotDuration' => sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60),
            'doctor' => $consultant->names,
            'summary' => $consultant->getHoursSummary(),
        ];
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
}
