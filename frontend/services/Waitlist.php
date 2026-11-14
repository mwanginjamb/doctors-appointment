<?php

namespace frontend\services;

use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\jobs\WaitlistJob;
use common\models\Consent;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\WaitlistEntry;
use Yii;

/**
 * F4 waiting list: patients wait for a time with a consultant at a facility; when one frees up
 * (a cancellation, a move, new hours), everyone waiting for it is told at once, with their link,
 * and the first to book gets it (BookingService's lock settles who).
 *
 * Notices follow these rules:
 *  - only times at least waitlist.minLeadMinutes ahead are announced;
 *  - a time is announced to a patient once; a time that is taken and frees up again is new again;
 *  - no notices in quiet hours (waitlist.quietFrom to waitlist.quietUntil): the sweep sends them in the morning;
 *  - at most waitlist.maxNoticesPerDay a day per entry;
 *  - email always (with an address), WhatsApp only with the patient's consent (F11).
 *
 * Triggers: Appointments::afterSave() queues a WaitlistJob when an appointment is cancelled or
 * moved; `yii waitlist/sweep` (cron, every 15 minutes) ends entries that no longer make sense and
 * catches everything else (new hours, time off removed, notices held over quiet hours).
 */
class Waitlist
{
    /** @var int|null fixed "now" for tests */
    public static $now;

    public static function now()
    {
        return self::$now ?? time();
    }

    public static function isEnabled()
    {
        return (bool) (Yii::$app->params['waitlist.enabled'] ?? true);
    }

    public static function param($name, $default)
    {
        return Yii::$app->params['waitlist.' . $name] ?? $default;
    }

    /** Longest window a patient can wait for, in days. */
    public static function maxDays()
    {
        return max(1, (int) self::param('maxDays', 30));
    }

    /**
     * Earliest start a patient can book from their link: the usual minimum notice; for an entry
     * waiting for something earlier today (a walk-in already at the clinic), only
     * waitlist.sameDayLeadMinutes.
     */
    public static function bookableFrom(?WaitlistEntry $entry = null)
    {
        if ($entry !== null && self::isSameDay($entry)) {
            return self::now() + (int) self::param('sameDayLeadMinutes', 15) * 60;
        }

        return self::now() + (int) (Yii::$app->params['booking.minNoticeMinutes'] ?? 0) * 60;
    }

    /** Earliest start worth announcing: far enough ahead for the patient to get there. */
    public static function announceFrom(?WaitlistEntry $entry = null)
    {
        if ($entry !== null && self::isSameDay($entry)) {
            return self::bookableFrom($entry);
        }
        $lead = max((int) self::param('minLeadMinutes', 120), (int) (Yii::$app->params['booking.minNoticeMinutes'] ?? 0));

        return self::now() + $lead * 60;
    }

    /** Waiting for something earlier than an appointment today: the patient is at (or near) the clinic. */
    public static function isSameDay(WaitlistEntry $entry)
    {
        $appointment = $entry->wantsEarlier() ? $entry->appointment : null;

        return $appointment !== null && $appointment->date === date('Y-m-d', self::now());
    }

    public static function isQuietTime($ts = null)
    {
        $from = (int) self::param('quietFrom', 21);
        $until = (int) self::param('quietUntil', 7);
        $hour = (int) date('G', $ts ?? self::now());
        if ($from === $until) {
            return false;
        }

        return $from > $until ? ($hour >= $from || $hour < $until) : ($hour >= $from && $hour < $until);
    }

    /**
     * Puts a patient on the list, or updates their window when they already wait for the same
     * consultant and facility (or the same appointment). The times free right now count as
     * already offered: the patient sees them on their page, and is only told about new ones.
     *
     * @param array $attributes patient_id, consultant_id (consultant.id), facility_id, from_date,
     *                          to_date, day_start, day_end, appointment_id
     * @param int|null $createdBy the patient, or the staff member adding them
     * @return WaitlistEntry
     */
    public static function join(array $attributes, $createdBy)
    {
        $match = [
            'patient_id' => $attributes['patient_id'],
            'consultant_id' => $attributes['consultant_id'],
            'status' => WaitlistEntry::STATUS_WAITING,
        ];
        $match += !empty($attributes['appointment_id'])
            ? ['appointment_id' => $attributes['appointment_id']]
            : ['facility_id' => $attributes['facility_id'], 'appointment_id' => null];

        $entry = WaitlistEntry::findOne($match) ?? new WaitlistEntry([
            'status' => WaitlistEntry::STATUS_WAITING,
            'created_at' => self::now(),
            'created_by' => $createdBy,
        ]);
        $entry->setAttributes($attributes, false);
        $entry->offered_slots = json_encode(self::flatten($entry->openSlots(self::bookableFrom($entry))));
        $entry->save(false);

        return $entry;
    }

    /**
     * After an appointment is saved (Appointments::afterSave):
     *  - a new booking within a waiting window of the same patient and consultant ends that wait;
     *  - an appointment moved into the window of its "something earlier" entry ends that entry;
     *  - a cancelled appointment ends the entries waiting to move it;
     *  - a cancelled or moved appointment frees a slot: the consultant's waiting list is checked.
     */
    public static function appointmentSaved(Appointments $appointment, $insert, array $changedAttributes)
    {
        if (!self::isEnabled()) {
            return;
        }
        try {
            $consultant = $appointment->consultant;
            if ($insert) {
                self::endWaitsBookedBy($appointment, $consultant);
                return;
            }

            $cancelled = array_key_exists('status', $changedAttributes)
                && $changedAttributes['status'] !== $appointment->status
                && $appointment->status === Appointments::STATUS_CANCELLED;
            $moved = !$cancelled && (isset($changedAttributes['date']) || isset($changedAttributes['time']) || isset($changedAttributes['consultant_id']));

            if ($cancelled) {
                foreach (WaitlistEntry::findAll(['appointment_id' => $appointment->id, 'status' => WaitlistEntry::STATUS_WAITING]) as $entry) {
                    $entry->end(WaitlistEntry::STATUS_EXPIRED);
                }
            }
            if ($moved && $consultant) {
                $start = $appointment->date . ' ' . $appointment->time;
                foreach (WaitlistEntry::findAll(['appointment_id' => $appointment->id, 'status' => WaitlistEntry::STATUS_WAITING]) as $entry) {
                    if ((int) $entry->consultant_id !== (int) $consultant->id) {
                        $entry->end(WaitlistEntry::STATUS_EXPIRED);
                    } elseif ($appointment->date >= $entry->from_date && $appointment->date <= $entry->to_date
                        && $start < ($changedAttributes['date'] ?? $appointment->date) . ' ' . ($changedAttributes['time'] ?? $appointment->time)) {
                        // Moved earlier (by staff, or through the waiting-list link)
                        $entry->end(WaitlistEntry::STATUS_BOOKED, $appointment->id);
                    }
                }
            }
            if ($cancelled || $moved) {
                $userIds = [(int) $appointment->consultant_id];
                if (!empty($changedAttributes['consultant_id'])) {
                    $userIds[] = (int) $changedAttributes['consultant_id'];
                }
                foreach (array_unique($userIds) as $userId) {
                    self::queueCheck($userId);
                }
            }
        } catch (\Throwable $e) {
            // The waiting list must never stop a booking from saving
            Yii::error('Waiting list after appointment #' . $appointment->id . ': ' . $e->getMessage(), 'waitlist');
        }
    }

    private static function endWaitsBookedBy(Appointments $appointment, ?Consultant $consultant)
    {
        if ($consultant === null) {
            return;
        }
        $entries = WaitlistEntry::find()->where([
            'patient_id' => $appointment->patient_id,
            'consultant_id' => $consultant->id,
            'status' => WaitlistEntry::STATUS_WAITING,
            'appointment_id' => null,
        ])->andWhere(['<=', 'from_date', $appointment->date])->andWhere(['>=', 'to_date', $appointment->date])->all();
        foreach ($entries as $entry) {
            $entry->end(WaitlistEntry::STATUS_BOOKED, $appointment->id);
        }
    }

    /**
     * Queues a check of the consultant's waiting list, when anyone is waiting for them.
     *
     * @param int $consultantUserId appointments.consultant_id
     */
    public static function queueCheck($consultantUserId)
    {
        $waiting = WaitlistEntry::find()->alias('w')
            ->innerJoin('{{%consultant}} c', 'c.id = w.consultant_id')
            ->where(['c.user_id' => $consultantUserId, 'w.status' => WaitlistEntry::STATUS_WAITING])
            ->exists();
        if ($waiting) {
            Yii::$app->queue->push(new WaitlistJob(['consultantUserId' => (int) $consultantUserId]));
        }
    }

    /**
     * Tells everyone waiting for the consultant about times that opened since they were last told.
     *
     * @param int $consultantUserId appointments.consultant_id
     * @return int notices sent
     */
    public static function notifyFor($consultantUserId)
    {
        if (!self::isEnabled()) {
            return 0;
        }
        $consultant = Consultant::findOne(['user_id' => $consultantUserId]);
        if ($consultant === null) {
            return 0;
        }
        $entries = WaitlistEntry::find()
            ->where(['consultant_id' => $consultant->id, 'status' => WaitlistEntry::STATUS_WAITING])
            ->with(['patient', 'facility', 'appointment'])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $sent = 0;
        foreach ($entries as $entry) {
            $entry->populateRelation('consultant', $consultant);
            $sent += self::check($entry) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Ends the entry when it no longer makes sense; otherwise tells the patient about new times.
     *
     * @return bool whether a notice was sent
     */
    public static function check(WaitlistEntry $entry)
    {
        $now = self::now();
        if ($entry->endReason($now) !== null) {
            $entry->end(WaitlistEntry::STATUS_EXPIRED);
            return false;
        }

        $open = self::flatten($entry->openSlots(self::announceFrom($entry)));
        $offered = $entry->getOfferedSlots();
        $new = array_values(array_diff($open, $offered));
        if (!$new) {
            // Forget times that were taken, so they count as new if they free up again
            $still = array_values(array_intersect($offered, self::flatten($entry->openSlots(self::bookableFrom($entry)))));
            if ($still !== $offered) {
                $entry->updateAttributes(['offered_slots' => json_encode($still)]);
            }
            return false;
        }
        if (self::isQuietTime($now)) {
            return false; // the sweep tells them in the morning
        }
        $today = date('Y-m-d', $now);
        $sentToday = $entry->notices_on === $today ? (int) $entry->notices_that_day : 0;
        if ($sentToday >= (int) self::param('maxNoticesPerDay', 3)) {
            return false;
        }

        $channels = self::send($entry, $new, count($open));
        $entry->updateAttributes([
            'offered_slots' => json_encode(array_values(array_unique(array_merge($offered, $open)))),
            'notices_sent' => (int) $entry->notices_sent + 1,
            'notices_on' => $today,
            'notices_that_day' => $sentToday + 1,
            'last_notified_at' => $now,
        ]);

        return (bool) $channels;
    }

    /**
     * Ends entries that no longer make sense and checks every consultant someone is waiting for.
     *
     * @return array ['checked' => consultants checked, 'sent' => notices sent]
     */
    public static function sweep()
    {
        $userIds = WaitlistEntry::find()->alias('w')
            ->innerJoin('{{%consultant}} c', 'c.id = w.consultant_id')
            ->where(['w.status' => WaitlistEntry::STATUS_WAITING])
            ->select('c.user_id')->distinct()->column();
        $sent = 0;
        foreach ($userIds as $userId) {
            $sent += self::notifyFor((int) $userId);
        }

        // Entries for consultants without a user (cannot be booked) end too
        foreach (WaitlistEntry::find()->where(['status' => WaitlistEntry::STATUS_WAITING])->andWhere(['<', 'to_date', date('Y-m-d', self::now())])->all() as $entry) {
            $entry->end(WaitlistEntry::STATUS_EXPIRED);
        }

        return ['checked' => count($userIds), 'sent' => $sent];
    }

    /**
     * Books a time from the patient's waiting-list link: a new appointment, or, for "something
     * earlier", moves their appointment. The entry is locked, so a double click books once; the
     * consultant's lock (BookingService) makes the first of several patients win the time.
     *
     * @return array [Appointments|null, string|null error]
     */
    public static function book(WaitlistEntry $entry, $date, $time)
    {
        $time = substr((string) $time, 0, 5);
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand('SELECT [[id]] FROM {{%waitlist_entry}} WHERE [[id]] = :id FOR UPDATE', [':id' => $entry->id])->queryScalar();
            $entry->refresh();
            if (!$entry->isWaiting()) {
                $transaction->rollBack();
                return [null, Yii::t('app', 'You are no longer on this waiting list.')];
            }
            if (!in_array($time, $entry->openSlots(self::bookableFrom($entry))[$date] ?? [], true)) {
                $transaction->rollBack();
                return [null, Yii::t('app', 'Sorry, that time has just been taken. Please choose another.')];
            }

            $service = Yii::createObject(BookingService::class);
            if ($entry->wantsEarlier()) {
                $appointment = $entry->getMovableAppointment();
                if ($appointment === null) {
                    $transaction->rollBack();
                    return [null, Yii::t('app', 'Your appointment can no longer be moved. Please call the clinic.')];
                }
                // Same day: the clinic offered this time to someone already there, so no notice
                $appointment->skipNotice = self::isSameDay($entry);
                // Moved by the patient: the consultant is told (F1)
                $ok = $service->reschedule($appointment, $date, $time . ':00', true);
            } else {
                $appointment = new Appointments([
                    'date' => $date,
                    'time' => $time . ':00',
                    'patient_id' => $entry->patient_id,
                    'consultant_id' => $entry->consultant->user_id,
                ]);
                // The clinic offered this time from its waiting list, as with a booking link
                $appointment->skipNotice = true;
                $ok = $service->book($appointment);
            }
            if (!$ok) {
                $transaction->rollBack();
                $errors = $appointment->getFirstErrors();
                return [null, $errors ? reset($errors) : Yii::t('app', 'Sorry, that time has just been taken. Please choose another.')];
            }
            if (!$entry->wantsEarlier()) {
                Appointments::updateAll(['created_by' => $entry->patient_id], ['id' => $appointment->id]);
            }
            $entry->refresh();
            $entry->end(WaitlistEntry::STATUS_BOOKED, $appointment->id);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return [$appointment, null];
    }

    /**
     * Tells a patient a staff member put them on the list, with their link (to see the times and to
     * leave the list).
     *
     * @return string[] channels used
     */
    public static function sendWelcome(WaitlistEntry $entry)
    {
        $url = $entry->getUrl();
        if ($url === null) {
            return [];
        }
        $doctor = $entry->consultant->names ?? 'your doctor';
        $text = 'Hello ' . self::firstName($entry) . ", you are on the waiting list for {$doctor} at " . ($entry->facility->name ?? 'the clinic')
            . ' (' . $entry->getWindowLabel() . '). We will message you when a time frees up. '
            . "To see free times or leave the list: {$url}";

        return self::deliver($entry, "You are on {$doctor}'s waiting list", $text, $url, 'See free times');
    }

    /**
     * @param string[] $new 'Y-m-d H:i' times not announced before, earliest first
     * @param int $total all times now open in the window
     * @return string[] channels used
     */
    private static function send(WaitlistEntry $entry, array $new, $total)
    {
        $url = $entry->getUrl();
        if ($url === null) {
            return [];
        }
        sort($new);
        $doctor = $entry->consultant->names ?? 'your doctor';
        $place = $entry->facility->name ?? 'the clinic';
        $first = date('l j M \a\t H:i', strtotime($new[0]));
        $others = $total - 1;
        $more = $others > 0 ? ' (and ' . ($others === 1 ? '1 other time' : "$others other times") . ')' : '';

        if ($entry->wantsEarlier() && ($appointment = $entry->getMovableAppointment())) {
            $current = date('l j M \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time));
            $subject = "An earlier time with {$doctor}";
            $text = 'Hello ' . self::firstName($entry) . ", an earlier time has opened with {$doctor} at {$place}: {$first}{$more}. "
                . "Your appointment is on {$current}. To move to the earlier time, choose it here: {$url}\n\n"
                . 'Times go to whoever chooses first. The same link lets you leave the waiting list.';
        } else {
            $subject = "A time has opened with {$doctor}";
            $text = 'Hello ' . self::firstName($entry) . ", a time has opened with {$doctor} at {$place}: {$first}{$more}. "
                . "Book it here: {$url}\n\n"
                . 'Times go to whoever books first. The same link lets you leave the waiting list.';
        }

        return self::deliver($entry, $subject, $text, $url, 'Choose a time');
    }

    private static function deliver(WaitlistEntry $entry, $subject, $text, $url, $linkLabel)
    {
        $patient = $entry->patient;
        if ($patient === null) {
            return [];
        }
        $channels = [];
        if ($patient->email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $patient->email,
                'toName' => $patient->full_name ?: null,
                'subject' => $subject,
                'text' => $text,
                'linkUrl' => $url,
                'linkLabel' => $linkLabel,
            ]));
            $channels[] = 'email';
        }
        // F11: WhatsApp only with the patient's consent
        if ($patient->phone_number && Consent::remindersAllowed($patient->id)) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $patient->phone_number, 'text' => $text]));
            $channels[] = 'whatsapp';
        }
        if (!$channels) {
            Yii::info("No way to reach the patient of waiting-list entry #{$entry->id}.", 'waitlist');
        }

        return $channels;
    }

    private static function firstName(WaitlistEntry $entry)
    {
        $name = trim((string) ($entry->patient->full_name ?? ''));

        return $name !== '' ? explode(' ', $name)[0] : 'there';
    }

    /**
     * @param array $slots Y-m-d => ['H:i', ...]
     * @return string[] 'Y-m-d H:i', earliest first
     */
    public static function flatten(array $slots)
    {
        $flat = [];
        foreach ($slots as $date => $times) {
            foreach ($times as $time) {
                $flat[] = $date . ' ' . $time;
            }
        }
        sort($flat);

        return $flat;
    }
}
