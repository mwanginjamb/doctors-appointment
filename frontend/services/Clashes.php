<?php

namespace frontend\services;

use common\components\RbacHelper;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\AppointmentClash;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantTimeOff;
use frontend\models\FacilityClosure;
use frontend\models\FrontDeskAssignment;
use frontend\models\PublicHoliday;
use Yii;

/**
 * F14: bookings that clash with a facility closure, a consultant's time off or a public holiday.
 *
 * Saving any of those keeps the bookings already made. Staff open the clash list
 * (/clash/<type>/<id>), may move some themselves, then tell the rest: each patient gets an email
 * (and WhatsApp with consent) that the appointment cannot go ahead, with their link to pick a new
 * time. That link works inside the usual 24-hour cut-off for these bookings. The day before, staff
 * are reminded of patients who have not moved (`php yii clash/remind`). Nothing is cancelled for them.
 *
 * Who sees and acts on a booking: whoever handles it (the consultant, their front desk at its
 * facility, the facility's admins, consultancy admins).
 */
class Clashes
{
    const TYPE_CLOSURE = 'closure';
    const TYPE_TIME_OFF = 'time_off';
    const TYPE_HOLIDAY = 'holiday';

    /** @return FacilityClosure|ConsultantTimeOff|PublicHoliday|null */
    public static function source($type, $id)
    {
        switch ($type) {
            case self::TYPE_CLOSURE:
                return FacilityClosure::findOne((int) $id);
            case self::TYPE_TIME_OFF:
                return ConsultantTimeOff::findOne((int) $id);
            case self::TYPE_HOLIDAY:
                return PublicHoliday::findOne((int) $id);
        }

        return null;
    }

    public static function typeOf($source)
    {
        if ($source instanceof FacilityClosure) {
            return self::TYPE_CLOSURE;
        }
        if ($source instanceof ConsultantTimeOff) {
            return self::TYPE_TIME_OFF;
        }

        return self::TYPE_HOLIDAY;
    }

    /** The clash list's address. */
    public static function url($source)
    {
        return ['/clash/view', 'type' => self::typeOf($source), 'id' => $source->id];
    }

    /**
     * Upcoming scheduled or confirmed bookings that clash with it now, earliest first.
     *
     * @return Appointments[]
     */
    public static function appointments($source)
    {
        if ($source instanceof ConsultantTimeOff) {
            $list = $source->findClashingAppointments();
        } else {
            $query = Appointments::find()
                ->where(['date' => $source->date])
                ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
                ->with(['patient', 'consultant', 'facility'])
                ->orderBy(['time' => SORT_ASC, 'id' => SORT_ASC]);
            if ($source instanceof FacilityClosure) {
                $query->andWhere(['facility_id' => $source->facility_id]);
            }
            $list = $query->all();
        }

        return array_values(array_filter($list, function (Appointments $a) { return $a->isFuture(); }));
    }

    /** Whether the appointment clashes with it now. */
    public static function clashes(Appointments $appointment, $source)
    {
        foreach (self::appointments($source) as $a) {
            if ((int) $a->id === (int) $appointment->id) {
                return true;
            }
        }

        return false;
    }

    /** Whether $user (default: the signed-in user) handles this booking. */
    public static function canHandle(Appointments $appointment)
    {
        $user = Yii::$app->user;
        if ($user->isGuest) {
            return false;
        }

        return (int) $appointment->consultant_id === (int) $user->id || FrontDesk::canHandle($appointment);
    }

    /** Whether the signed-in user manages the closure, time off or holiday itself. */
    public static function canManageSource($source)
    {
        $user = Yii::$app->user;
        if ($user->isGuest) {
            return false;
        }
        if ($source instanceof FacilityClosure) {
            return $source->facility !== null && $user->can(RbacHelper::PERM_MANAGE_FACILITY_CLOSURES, ['facility' => $source->facility]);
        }
        if ($source instanceof ConsultantTimeOff) {
            $consultant = $source->consultant;

            return $consultant !== null && ((int) $consultant->user_id === (int) $user->id
                || $user->can(RbacHelper::PERM_MANAGE_AVAILABILITY, ['consultant' => $consultant]));
        }

        return $user->can(RbacHelper::PERM_MANAGE_REFERENCE_DATA);
    }

    /**
     * The list for the signed-in user: bookings clashing now that they handle, with whether the
     * patient was told; and those told earlier that no longer clash (moved or cancelled).
     *
     * @return array[] each ['appointment' => Appointments, 'clash' => AppointmentClash|null, 'open' => bool]
     */
    public static function rowsFor($source)
    {
        $type = self::typeOf($source);
        $told = AppointmentClash::find()->where(['source_type' => $type, 'source_id' => $source->id])->indexBy('appointment_id')->all();
        $rows = [];
        $seen = [];
        foreach (self::appointments($source) as $appointment) {
            $seen[(int) $appointment->id] = true;
            if (self::canHandle($appointment)) {
                $rows[] = ['appointment' => $appointment, 'clash' => $told[$appointment->id] ?? null, 'open' => true];
            }
        }
        foreach ($told as $appointmentId => $clash) {
            if (isset($seen[(int) $appointmentId]) || ($appointment = $clash->appointment) === null || !self::canHandle($appointment)) {
                continue;
            }
            $rows[] = ['appointment' => $appointment, 'clash' => $clash, 'open' => false];
        }

        return $rows;
    }

    /**
     * Tells each patient whose booking clashes now, that the signed-in user handles, and who has
     * not been told yet; or only those in $appointmentIds.
     *
     * @param string $message optional words from staff, added to the notice
     * @return int patients told
     */
    public static function tell($source, $message = '', ?array $appointmentIds = null)
    {
        $type = self::typeOf($source);
        $userId = Yii::$app->user->isGuest ? null : (int) Yii::$app->user->id;
        $message = trim(mb_substr((string) $message, 0, 500));
        $n = 0;
        foreach (self::appointments($source) as $appointment) {
            if (!self::canHandle($appointment) || ($appointmentIds !== null && !in_array((int) $appointment->id, array_map('intval', $appointmentIds), true))) {
                continue;
            }
            $clash = AppointmentClash::findOne(['appointment_id' => $appointment->id, 'source_type' => $type, 'source_id' => $source->id])
                ?? new AppointmentClash(['appointment_id' => $appointment->id, 'source_type' => $type, 'source_id' => $source->id, 'created_at' => time()]);
            if ($clash->told_at) {
                continue;
            }
            self::notifyPatient($appointment, $source, $message);
            $clash->told_at = time();
            $clash->told_by = $userId;
            $clash->save(false);
            $n++;
        }

        return $n;
    }

    /**
     * What patients are told: why the appointment cannot go ahead. Time off gives no reason; a
     * closure and a holiday say what they are.
     */
    public static function reasonForPatient($source, Appointments $appointment)
    {
        $doctor = $appointment->consultant->names ?? 'your doctor';
        if ($source instanceof FacilityClosure) {
            $name = $source->facility->name ?? 'The clinic';

            return "{$name} is closed that day" . ($source->reason ? " ({$source->reason})" : '') . '.';
        }
        if ($source instanceof ConsultantTimeOff) {
            return "{$doctor} is not available at that time.";
        }

        return "It is a public holiday ({$source->name}).";
    }

    /** For staff: "Avenue Clinic closed, Mon 1 Dec 2026 (Staff training)". */
    public static function describe($source)
    {
        if ($source instanceof FacilityClosure) {
            return ($source->facility->name ?? 'Facility') . ' closed, ' . date('D j M Y', strtotime($source->date))
                . ($source->reason ? " ({$source->reason})" : '');
        }
        if ($source instanceof ConsultantTimeOff) {
            $from = strtotime($source->start_at);
            $to = strtotime($source->end_at) - 1;

            return ($source->consultant->names ?? 'Consultant') . ' away, ' . date('D j M Y', $from)
                . (date('Y-m-d', $from) !== date('Y-m-d', $to) ? ' to ' . date('D j M Y', $to) : '')
                . ($source->reason ? " ({$source->reason})" : '');
        }

        return $source->name . ', ' . date('D j M Y', strtotime($source->date)) . ' (public holiday)';
    }

    private static function notifyPatient(Appointments $appointment, $source, $message)
    {
        $patient = $appointment->patient;
        if ($patient === null) {
            return;
        }
        $doctor = $appointment->consultant->names ?? 'your doctor';
        $when = date('l j F Y \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time));
        $facility = $appointment->facility;
        $call = $facility && $facility->phone ? " or call {$facility->name} on {$facility->phone}" : ' or call the clinic';
        $name = $patient->full_name ?: 'there';
        $text = "Hello {$name},\n\nYour appointment with {$doctor} on {$when} at {$appointment->getPlaceText()} cannot go ahead. "
            . self::reasonForPatient($source, $appointment)
            . ($message !== '' ? "\n\n{$message}" : '')
            . "\n\nPlease choose a new time{$call}. Until you do, your booking stays as it is.";
        $link = SelfServiceLink::url($appointment);
        $subject = "Please choose a new time for your appointment with {$doctor}";

        if ($patient->email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $patient->email, 'toName' => $patient->full_name ?: null, 'subject' => $subject,
                'text' => $text, 'linkUrl' => $link, 'linkLabel' => 'Choose a new time',
            ]));
        }
        // F11: WhatsApp only with the patient's consent
        if ($patient->phone_number && \common\models\Consent::remindersAllowed($patient->id)) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $patient->phone_number, 'text' => $text . "\n\n" . $link]));
        }
    }

    /**
     * The patient was told and the booking still clashes: they may move it inside the cut-off,
     * and the board shows "Needs a new time".
     */
    public static function mustMove(Appointments $appointment)
    {
        return self::pendingFor($appointment) !== null;
    }

    /** @return AppointmentClash|null the told clash still in force for this booking */
    public static function pendingFor(Appointments $appointment)
    {
        if (!$appointment->isFuture() || !in_array($appointment->status, [null, Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED], true)) {
            return null;
        }
        foreach (AppointmentClash::find()->where(['appointment_id' => $appointment->id])->andWhere(['not', ['told_at' => null]])->all() as $clash) {
            $source = self::source($clash->source_type, $clash->source_id);
            if ($source !== null && self::clashes($appointment, $source)) {
                return $clash;
            }
        }

        return null;
    }

    /**
     * Of these appointment ids, the ones whose patient was told and that still clash.
     *
     * @param int[] $ids
     * @return array id => true
     */
    public static function needingNewTime(array $ids)
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        $clashes = AppointmentClash::find()->where(['appointment_id' => $ids])->andWhere(['not', ['told_at' => null]])->with('appointment')->all();
        foreach ($clashes as $clash) {
            if (isset($out[$clash->appointment_id]) || $clash->appointment === null) {
                continue;
            }
            if (self::mustMove($clash->appointment)) {
                $out[(int) $clash->appointment_id] = true;
            }
        }

        return $out;
    }

    /**
     * Daily: for each booking tomorrow (or later today) whose patient was told but has not moved,
     * reminds the consultant, their front desk at that facility and the facility's admins, once.
     *
     * @return string[] what was sent, for the console
     */
    public static function remindStaff()
    {
        $until = date('Y-m-d', strtotime('+1 day'));
        $clashes = AppointmentClash::find()->alias('c')->innerJoinWith('appointment a')
            ->where(['not', ['c.told_at' => null]])->andWhere(['c.staff_reminded_at' => null])
            ->andWhere(['between', 'a.date', date('Y-m-d'), $until])
            ->orderBy(['a.date' => SORT_ASC, 'a.time' => SORT_ASC])->all();
        $byConsultant = [];
        foreach ($clashes as $clash) {
            $appointment = $clash->appointment;
            if (!self::mustMove($appointment)) {
                continue;
            }
            $byConsultant[(int) $appointment->consultant_id][] = $clash;
        }

        $lines = [];
        foreach ($byConsultant as $consultantUserId => $list) {
            $consultant = Consultant::findOne(['user_id' => $consultantUserId]);
            $items = [];
            $facilityIds = [];
            foreach ($list as $clash) {
                $a = $clash->appointment;
                $facilityIds[(int) $a->facility_id] = true;
                $items[] = '- ' . date('D j M H:i', strtotime($a->date . ' ' . $a->time)) . ': ' . ($a->patient->full_name ?? 'Patient')
                    . ($a->facility ? " at {$a->facility->name}" : '') . '. ' . self::appointmentUrl($a);
            }
            $doctor = $consultant->names ?? 'the consultant';
            $text = "These patients were told their appointment with {$doctor} cannot go ahead, and have not chosen a new time:\n\n"
                . implode("\n", $items)
                . "\n\nPlease call them to agree a new time, or cancel the appointment. Nothing is cancelled for them.";
            $subject = 'Patients still to move: ' . count($items) . " with {$doctor}";

            foreach (self::staffFor($consultant, $consultantUserId, array_keys($facilityIds)) as $user) {
                Yii::$app->queue->push(new SendEmailMessageJob([
                    'to' => $user->email, 'toName' => $user->full_name ?: null, 'subject' => $subject,
                    'text' => 'Dear ' . ($user->full_name ?: $user->username) . ",\n\n" . $text,
                ]));
            }
            foreach ($list as $clash) {
                $clash->updateAttributes(['staff_reminded_at' => time()]);
            }
            $lines[] = "{$doctor}: " . count($items) . ' still to move';
        }

        return $lines;
    }

    /** @return User[] the consultant, their front desk at these facilities, and the facilities' admins */
    private static function staffFor($consultant, $consultantUserId, array $facilityIds)
    {
        $users = [];
        $add = function ($user) use (&$users) {
            if ($user && $user->email && (int) $user->status === User::STATUS_ACTIVE) {
                $users[(int) $user->id] = $user;
            }
        };
        $doctor = User::findOne($consultantUserId);
        if ($consultant && $consultant->consultant_email && $doctor) {
            $doctor->email = $consultant->consultant_email;
        }
        $add($doctor);
        if ($consultant) {
            $deskIds = FrontDeskAssignment::find()->select('user_id')->where(['consultant_id' => $consultant->id])->column();
            foreach (User::find()->where(['id' => $deskIds])->all() as $desk) {
                if ($desk->facility_id === null || in_array((int) $desk->facility_id, $facilityIds, true)) {
                    $add($desk);
                }
            }
        }
        foreach (User::find()->where(['role' => User::ACCOUNT_FACILITY_ADMIN, 'facility_id' => $facilityIds ?: [0]])->all() as $admin) {
            $add($admin);
        }

        return array_values($users);
    }

    // ---- Part 2: moving and cancelling in bulk ----------------------------------------------

    /** How many days after the chosen one a move may spill over to. */
    const SPILL_DAYS = 7;

    /**
     * The open bookings of this clash that the signed-in user handles, among $ids, in their order.
     *
     * @param int[] $ids
     * @return Appointments[]
     */
    public static function pick($source, array $ids)
    {
        $ids = array_map('intval', $ids);
        $out = [];
        foreach (self::appointments($source) as $appointment) {
            if (in_array((int) $appointment->id, $ids, true) && self::canHandle($appointment)) {
                $out[] = $appointment;
            }
        }

        return $out;
    }

    /**
     * Facilities bookings may be moved to: those the consultants of $appointments work at, limited
     * to a front desk's own facility.
     *
     * @param Appointments[] $appointments
     * @return array id => name
     */
    public static function facilityChoices(array $appointments)
    {
        $own = FrontDesk::facilityId();
        $choices = [];
        foreach ($appointments as $appointment) {
            foreach ($appointment->consultant->engagements ?? [] as $engagement) {
                $facility = $engagement->facility;
                if ($facility !== null && $facility->isActive() && ($own === null || (int) $own === (int) $facility->id)) {
                    $choices[(int) $facility->id] = $facility->name;
                }
            }
        }
        asort($choices);

        return $choices;
    }

    /**
     * Plans moving $appointments (in order) to $date or, when that day is full, the following days:
     * each takes the first free slot with its own consultant, keeping its length, without two
     * landing on each other. At $facilityId when given (if the consultant works there), else
     * where it was booked.
     *
     * @param Appointments[] $appointments in the order to place them
     * @return array[] each ['appointment', 'date' => Y-m-d|null, 'time' => H:i|null, 'facilityId' => int|null, 'problem' => string|null]
     */
    public static function planMove(array $appointments, $date, $facilityId = null)
    {
        $plan = [];
        $taken = []; // consultant user id => list of [start, end] timestamps already planned
        $to = date('Y-m-d', strtotime($date . ' +' . self::SPILL_DAYS . ' days'));
        foreach ($appointments as $appointment) {
            $consultant = $appointment->consultant;
            $target = $facilityId ? (int) $facilityId : (int) $appointment->facility_id;
            $row = ['appointment' => $appointment, 'date' => null, 'time' => null, 'facilityId' => $target, 'problem' => null];
            if ($consultant === null || ($facilityId && $consultant->getEngagement($target) === null)) {
                $row['problem'] = Yii::t('app', '{doctor} does not work at that facility.', ['doctor' => $consultant->names ?? 'The consultant']);
                $plan[] = $row;
                continue;
            }
            $slots = (new \frontend\models\SlotFinder([
                'consultant' => $consultant,
                'from' => $date,
                'to' => $to,
                'excludeAppointmentId' => $appointment->id,
                'facilityId' => $target ?: null,
                'notBefore' => time(),
            ]))->find();
            $length = $appointment->getDurationMinutes() * 60;
            $mine = &$taken[(int) $appointment->consultant_id];
            $mine = $mine ?? [];
            foreach ($slots as $day => $times) {
                foreach ($times as $time) {
                    $start = strtotime($day . ' ' . $time);
                    $clear = true;
                    foreach ($mine as [$s, $e]) {
                        if ($start < $e && $start + $length > $s) {
                            $clear = false;
                            break;
                        }
                    }
                    if ($clear) {
                        $row['date'] = $day;
                        $row['time'] = $time;
                        $mine[] = [$start, $start + $length];
                        break 2;
                    }
                }
            }
            unset($mine);
            if ($row['date'] === null) {
                $row['problem'] = Yii::t('app', 'No free time in the {n} days from that date.', ['n' => self::SPILL_DAYS + 1]);
            }
            $plan[] = $row;
        }

        return $plan;
    }

    /**
     * Moves each booking to its planned time. The patient is told as usual (moved by the clinic,
     * with their link); $note, if any, is added to that message.
     *
     * @param array $times appointment id => [Y-m-d, H:i]
     * @return array ['moved' => Appointments[], 'failed' => [id => reason]]
     */
    public static function move($source, array $times, $note = '')
    {
        $note = trim(mb_substr((string) $note, 0, 500));
        $moved = [];
        $failed = [];
        foreach (self::pick($source, array_keys($times)) as $appointment) {
            [$date, $time] = $times[$appointment->id];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || !preg_match('/^\d{2}:\d{2}$/', (string) $time)) {
                $failed[(int) $appointment->id] = Yii::t('app', 'No time was planned.');
                continue;
            }
            $appointment->noticeNote = $note;
            if (Yii::createObject(BookingService::class)->reschedule($appointment, $date, $time . ':00')) {
                self::remember($source, $appointment);
                $moved[] = $appointment;
            } else {
                $errors = $appointment->getFirstErrors();
                $failed[(int) $appointment->id] = $errors ? reset($errors) : Yii::t('app', 'It could not be moved.');
                $appointment->refresh();
            }
        }

        return ['moved' => $moved, 'failed' => $failed];
    }

    /**
     * Cancels the bookings as the clinic's doing: a closure or holiday on the facility's side, time
     * off on the consultant's. A paid fee becomes a credit (F23); the patient is told as usual, with
     * $note and a link to book again.
     *
     * @param Appointments[] $appointments
     * @return int cancelled
     */
    public static function cancel($source, array $appointments, $note = '')
    {
        $note = trim(mb_substr((string) $note, 0, 500));
        $reason = $source instanceof ConsultantTimeOff ? Appointments::REASON_CLINIC : Appointments::REASON_FACILITY_CLOSED;
        $n = 0;
        foreach ($appointments as $appointment) {
            $appointment->noticeNote = $note;
            if ($appointment->cancel(mb_substr(self::describe($source) . ($note !== '' ? ': ' . $note : ''), 0, 500), $reason)) {
                self::remember($source, $appointment);
                $n++;
            }
        }

        return $n;
    }

    /** Keeps a moved or cancelled booking on the clash, so its list shows it under Done. */
    private static function remember($source, Appointments $appointment)
    {
        $key = ['appointment_id' => $appointment->id, 'source_type' => self::typeOf($source), 'source_id' => $source->id];
        if (!AppointmentClash::find()->where($key)->exists()) {
            (new AppointmentClash($key + ['created_at' => time()]))->save(false);
        }
    }

    /** The appointment's page for staff, as an absolute address (also from the console). */
    private static function appointmentUrl(Appointments $appointment)
    {
        return rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/') . '/appointments/view?id=' . (int) $appointment->id;
    }
}
