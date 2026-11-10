<?php

namespace frontend\services;

use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantTimeOff;
use frontend\models\PublicHoliday;
use Yii;
use yii\base\BaseObject;
use yii\db\Expression;

/**
 * Booking and moving appointments, and the rules a slot must meet.
 *
 * book() and reschedule() are the ways in: they save while holding a lock on the consultant, so two
 * requests cannot both take the same slot. The rules run as part of saving
 * (Appointments::validateSlot asks slotProblem()), so every save is checked the same way.
 *
 * Each rule is its own method, returning why a slot cannot be booked or null when it can. The
 * timing rules take "now" from $now, so tests can fix the clock.
 */
class BookingService extends BaseObject
{
    /** @var int|null the current time, as a timestamp; null for the real clock */
    public $now;

    /** @var int|null minimum notice for patients, in minutes; null for booking.minNoticeMinutes */
    public $minNoticeMinutes;

    /** @var int|null furthest a booking can be ahead, in days; null for booking.maxDaysAhead */
    public $maxDaysAhead;

    public function init()
    {
        parent::init();
        $params = Yii::$app->params;
        $this->minNoticeMinutes = (int) ($this->minNoticeMinutes ?? $params['booking.minNoticeMinutes'] ?? 0);
        $this->maxDaysAhead = (int) ($this->maxDaysAhead ?? $params['booking.maxDaysAhead'] ?? 0);
    }

    /**
     * Books a new appointment. On failure the reasons are in $appointment->errors.
     */
    public function book(Appointments $appointment)
    {
        return $this->save($appointment);
    }

    /**
     * Moves an appointment to another start time, keeping the length it was booked with. On failure
     * the reasons are in $appointment->errors.
     *
     * @param bool $byPatient the patient moved it themselves (self-service), so the consultant is told
     */
    public function reschedule(Appointments $appointment, $date, $time, $byPatient = false)
    {
        $appointment->date = $date;
        $appointment->time = $time;
        $appointment->changedByPatient = $byPatient;

        return $this->save($appointment);
    }

    /**
     * Saves the appointment while holding a row lock on its consultant, so two concurrent requests
     * cannot both pass the overlap check for the same slot.
     */
    public function save(Appointments $appointment)
    {
        $db = Appointments::getDb();
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand('SELECT [[id]] FROM {{%consultant}} WHERE [[user_id]] = :uid FOR UPDATE', [
                ':uid' => $appointment->consultant_id,
            ])->queryScalar();

            if ($appointment->save()) {
                $transaction->commit();
                return true;
            }
            $transaction->rollBack();
            return false;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Why the appointment's slot cannot be booked, or null when it can. Rules are checked in order
     * and the first problem is returned.
     *
     * Also settles the appointment's length (a new booking, or one moved to another consultant,
     * takes that consultant's current length; a moved one keeps its own) and normalises its time to
     * H:i:s.
     *
     * @return string|null
     */
    public function slotProblem(Appointments $appointment)
    {
        // A cancelled appointment stays as history; moving it would bring it back with reminders
        if (!$appointment->isNewRecord && $appointment->getOldAttribute('status') === Appointments::STATUS_CANCELLED) {
            return Yii::t('app', 'This appointment was cancelled and cannot be rescheduled. Book a new one instead.');
        }

        $startTs = strtotime($appointment->date . ' ' . $appointment->time);
        if ($startTs === false) {
            return Yii::t('app', 'Invalid appointment time.');
        }

        if ($appointment->isNewRecord || $appointment->isAttributeChanged('consultant_id', false) || !$appointment->duration_minutes) {
            $appointment->duration_minutes = $appointment->consultant
                ? $appointment->consultant->getSessionMinutes()
                : Appointments::defaultSessionMinutes();
        }
        $endTs = $startTs + $appointment->getDurationMinutes() * 60;
        $appointment->time = date('H:i:s', $startTs);

        return $this->startMarkProblem($startTs)
            ?? $this->timingProblem($startTs, $appointment->skipNotice || $this->isClinicSide($appointment))
            ?? $this->availabilityProblem($appointment->consultant, $startTs, $endTs)
            ?? $this->overlapProblem($appointment->consultant_id, $startTs, $endTs, $appointment->id);
    }

    /**
     * Starts fall on 5-minute marks (10:05 yes, 10:07 no).
     */
    public function startMarkProblem($startTs)
    {
        if ((int) date('i', $startTs) % 5 === 0 && (int) date('s', $startTs) === 0) {
            return null;
        }

        return Yii::t('app', 'Choose a start time on a 5-minute mark, such as {example}.', [
            'example' => date('H:', $startTs) . sprintf('%02d', intdiv((int) date('i', $startTs), 5) * 5),
        ]);
    }

    /**
     * In the future, with enough notice, and not too far ahead.
     *
     * @param bool $skipNotice the clinic is booking (the consultant or their front desk, or a booking
     *                         link they sent): walk-ins need no notice
     */
    public function timingProblem($startTs, $skipNotice = false)
    {
        $now = $this->now();
        if ($startTs <= $now) {
            return Yii::t('app', 'Appointments must be booked in the future.');
        }

        $notice = $this->minNoticeMinutes;
        if ($notice > 0 && !$skipNotice && $startTs < $now + $notice * 60) {
            return Yii::t('app', 'Appointments must be booked at least {notice} in advance.', [
                'notice' => $notice % 60 === 0 ? ($notice === 60 ? '1 hour' : ($notice / 60) . ' hours') : $notice . ' minutes',
            ]);
        }

        $maxDays = $this->maxDaysAhead;
        if ($maxDays > 0 && date('Y-m-d', $startTs) > date('Y-m-d', strtotime("+{$maxDays} days", $now))) {
            return Yii::t('app', 'Appointments can be booked at most {n} days ahead.', ['n' => $maxDays]);
        }

        return null;
    }

    /**
     * The consultant takes bookings and is in for the whole session: not deactivated, not a public
     * holiday, not on time off, and inside one of their opening ranges that day.
     */
    public function availabilityProblem(?Consultant $consultant, $startTs, $endTs)
    {
        $date = date('Y-m-d', $startTs);
        $doctor = $consultant->names ?? Yii::t('app', 'The doctor');

        // A deactivated consultant keeps their history but takes no new bookings or reschedules
        if ($consultant && !$consultant->isTakingBookings()) {
            return Yii::t('app', '{doctor} is no longer taking appointments.', ['doctor' => $doctor]);
        }

        // The clinic is closed on public holidays, for every consultant
        if ($holiday = PublicHoliday::findOnDate($date)) {
            return Yii::t('app', '{date} is a public holiday ({name}); the clinic is closed.', [
                'date' => date('l j M', $startTs),
                'name' => $holiday->name,
            ]);
        }

        // The consultant's time off. Its reason is private, so patients only see the dates.
        if ($consultant && ($away = ConsultantTimeOff::overlapping($consultant->id, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)))) {
            return Yii::t('app', '{doctor} is away {period}. Please choose another time.', [
                'doctor' => $doctor,
                'period' => $away[0]->getLabel(),
            ]);
        }

        // The whole session must fit inside one of the consultant's opening ranges that day
        $ranges = $consultant ? $consultant->getRangesFor($date) : [];
        if (!$ranges) {
            return Yii::t('app', "{doctor} doesn't see patients on {day}s.", [
                'doctor' => $doctor,
                'day' => date('l', $startTs),
            ]);
        }
        $minutes = intdiv($endTs - $startTs, 60);
        if (!$consultant->isOpenFor($date, date('H:i:s', $startTs), $minutes)) {
            return Yii::t('app', "That time is outside {doctor}'s hours on {day}: {hours}. A {minutes}-minute session must fit within them.", [
                'doctor' => $doctor,
                'day' => date('l', $startTs),
                'hours' => implode(', ', array_map(function ($r) {
                    return substr($r[0], 0, 5) . '–' . substr($r[1], 0, 5);
                }, $ranges)),
                'minutes' => $minutes,
            ]);
        }

        return null;
    }

    /**
     * No other active appointment with the consultant overlaps the session. Two slots overlap when
     * each starts before the other ends; each existing appointment is measured by its own length.
     *
     * @param int $consultantUserId the consultant's user id (appointments.consultant_id)
     * @param int|null $ignoreId the appointment being moved, which does not clash with itself
     */
    public function overlapProblem($consultantUserId, $startTs, $endTs, $ignoreId = null)
    {
        $conflict = Appointments::find()
            ->where(['date' => date('Y-m-d', $startTs), 'consultant_id' => $consultantUserId])
            ->andWhere(['or', ['status' => null], ['<>', 'status', Appointments::STATUS_CANCELLED]])
            ->andWhere(['<', 'time', date('H:i:s', $endTs)])
            ->andWhere(new Expression('ADDTIME([[time]], SEC_TO_TIME(COALESCE(NULLIF([[duration_minutes]], 0), :fallback) * 60)) > :start', [
                ':fallback' => Appointments::defaultSessionMinutes(),
                ':start' => date('H:i:s', $startTs),
            ]))
            ->andFilterWhere(['<>', 'id', $ignoreId])
            ->exists();

        return $conflict ? Yii::t('app', 'The doctor is not available at this time.') : null;
    }

    /**
     * Whether the signed-in user books on the clinic's side: the consultant the appointment is with,
     * or front desk assigned to them. False in console runs, which have no user.
     */
    private function isClinicSide(Appointments $appointment)
    {
        $user = Yii::$app->get('user', false);
        if ($user === null || $user->isGuest) {
            return false;
        }

        // RBAC: the consultant themselves (isTheConsultant) or their front desk (assignedConsultant)
        return FrontDesk::canBookFor($appointment->consultant_id);
    }

    private function now()
    {
        return $this->now ?? time();
    }
}
