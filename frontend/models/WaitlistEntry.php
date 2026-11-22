<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use common\models\User;
use Yii;
use yii\db\ActiveRecord;
use yii\helpers\Url;

/**
 * F4: a patient waiting for a time with a consultant at a facility, within a window of dates and
 * optionally part of the day. With an appointment_id, they wait for something earlier than that
 * appointment, and taking a time moves it.
 *
 * The patient's link (/waitlist/<id>/<signature>) shows the free times in the window, books one and
 * lets them leave the list. The signature is an HMAC of the entry, so nothing secret is stored.
 *
 * @property int $id
 * @property int $patient_id user.id
 * @property int $consultant_id consultant.id
 * @property int $facility_id
 * @property string $from_date Y-m-d
 * @property string $to_date Y-m-d
 * @property string|null $day_start H:i:s
 * @property string|null $day_end H:i:s
 * @property int|null $appointment_id the appointment to move to an earlier time
 * @property string $status waiting, booked, left, expired
 * @property string|null $offered_slots JSON list of 'Y-m-d H:i' the patient was last told about
 * @property int $notices_sent
 * @property string|null $notices_on Y-m-d
 * @property int $notices_that_day
 * @property int|null $last_notified_at
 * @property int|null $booked_appointment_id
 * @property int|null $ended_at
 * @property int|null $created_at
 * @property int|null $created_by
 */
class WaitlistEntry extends ActiveRecord
{
    const STATUS_WAITING = 'waiting';
    const STATUS_BOOKED = 'booked';
    const STATUS_LEFT = 'left';
    const STATUS_EXPIRED = 'expired';

    /** Parts of the day a patient can choose: key => [day_start, day_end] */
    const PARTS_OF_DAY = [
        'any' => [null, null],
        'morning' => ['00:00:00', '12:00:00'],
        'afternoon' => ['12:00:00', '23:59:00'],
    ];

    public static function tableName()
    {
        return '{{%waitlist_entry}}';
    }

    public function behaviors()
    {
        // The bookkeeping of notices is not worth a log line each time
        return ['audit' => [
            'class' => AuditBehavior::class,
            'entityType' => 'waitlist_entry',
            'exclude' => ['offered_slots', 'notices_sent', 'notices_on', 'notices_that_day', 'last_notified_at'],
        ]];
    }

    public static function statusLabels()
    {
        return [
            self::STATUS_WAITING => 'Waiting',
            self::STATUS_BOOKED => 'Booked',
            self::STATUS_LEFT => 'Left the list',
            self::STATUS_EXPIRED => 'Ended',
        ];
    }

    /**
     * Limits a query (aliased 'w') to the entries the current staff user handles: a consultant's
     * own; front desk, their consultants at their facility; admins and facility admins as on the
     * board.
     *
     * @param \yii\db\ActiveQuery $query
     * @return \yii\db\ActiveQuery
     */
    public static function scopeToStaff($query)
    {
        $query->innerJoin('{{%consultant}} c', 'c.id = w.consultant_id');
        $identity = Yii::$app->user->identity;
        if ($identity === null) {
            return $query->andWhere('0=1');
        }
        if ($identity->isConsultantAccount()) {
            return $query->andWhere(['c.user_id' => $identity->id]);
        }
        $consultants = \frontend\components\FrontDesk::consultantUserIds();
        if ($consultants !== null) {
            $query->andWhere(['c.user_id' => $consultants ?: [0]]);
        }
        if (($facilityId = \frontend\components\FrontDesk::facilityId()) !== null) {
            $query->andWhere(['w.facility_id' => $facilityId]);
        }

        return $query;
    }

    /** How many patients the current staff user sees waiting. */
    public static function countWaitingForStaff()
    {
        return (int) self::scopeToStaff(static::find()->alias('w'))->andWhere(['w.status' => self::STATUS_WAITING])->count();
    }

    public function getPatient()
    {
        return $this->hasOne(User::class, ['id' => 'patient_id']);
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }

    public function getBookedAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'booked_appointment_id']);
    }

    public function isWaiting()
    {
        return $this->status === self::STATUS_WAITING;
    }

    /** Waiting for something earlier than an appointment they have. */
    public function wantsEarlier()
    {
        return $this->appointment_id !== null;
    }

    /**
     * The appointment to move, while it is still on and ahead; else null.
     *
     * @return Appointments|null
     */
    public function getMovableAppointment()
    {
        $appointment = $this->appointment_id ? $this->appointment : null;
        if ($appointment === null || !$appointment->canBeCancelled()) {
            return null;
        }

        return $appointment;
    }

    /**
     * Ends the entry (booked, left or expired). Does nothing when it has already ended.
     *
     * @param int|null $appointmentId the appointment booked, for STATUS_BOOKED
     */
    public function end($status, $appointmentId = null)
    {
        if (!$this->isWaiting()) {
            return false;
        }
        $this->status = $status;
        $this->ended_at = time();
        $this->booked_appointment_id = $appointmentId;

        return $this->save(false, ['status', 'ended_at', 'booked_appointment_id']);
    }

    /**
     * Why the entry should end now, or null while it still makes sense: its window has passed, or
     * the appointment it would move is gone or past.
     *
     * @param int $now
     */
    public function endReason($now)
    {
        if ($this->to_date < date('Y-m-d', $now)) {
            return 'window passed';
        }
        if ($this->wantsEarlier() && $this->getMovableAppointment() === null) {
            return 'appointment gone';
        }
        if ($this->consultant === null || !$this->consultant->isTakingBookings()) {
            return 'consultant not taking bookings';
        }

        return null;
    }

    /**
     * Free start times in the window from $notBefore on: at the facility, inside the part of the
     * day, and, for "something earlier", before the appointment it would move (which is not counted
     * as busy).
     *
     * @param int $notBefore earliest start, as a timestamp
     * @return array Y-m-d => list of 'H:i' (only dates with free times)
     */
    public function openSlots($notBefore)
    {
        $from = max($this->from_date, date('Y-m-d', $notBefore));
        $to = $this->to_date;
        $before = null;
        if ($this->wantsEarlier()) {
            $appointment = $this->getMovableAppointment();
            if ($appointment === null) {
                return [];
            }
            $before = strtotime($appointment->date . ' ' . $appointment->time);
            $to = min($to, $appointment->date);
        }
        if ($this->consultant === null || $from > $to) {
            return [];
        }
        // F25: no new bookings offered while the consultant's online booking is paused; a patient
        // waiting to move a booking they have is still offered earlier times
        if ($before === null && $this->consultant->isOnlineBookingPaused()) {
            return [];
        }

        $slots = (new SlotFinder([
            'consultant' => $this->consultant,
            'from' => $from,
            'to' => $to,
            'dayStart' => $this->day_start,
            'dayEnd' => $this->day_end,
            'facilityId' => $this->facility_id,
            'excludeAppointmentId' => $this->appointment_id,
            'notBefore' => $notBefore,
        ]))->find();

        $open = [];
        foreach ($slots as $date => $times) {
            foreach ($times as $time) {
                if ($before !== null && strtotime($date . ' ' . $time) >= $before) {
                    continue;
                }
                $open[$date][] = $time;
            }
        }

        return $open;
    }

    /** @return string[] 'Y-m-d H:i' the patient was last told about */
    public function getOfferedSlots()
    {
        $slots = json_decode((string) $this->offered_slots, true);

        return is_array($slots) ? $slots : [];
    }

    /**
     * "Any time, Mon 17 Nov – Fri 28 Nov", "Mornings, ...", "Earlier than Fri 28 Nov at 10:00".
     */
    public function getWindowLabel()
    {
        $part = array_search([$this->day_start, $this->day_end], self::PARTS_OF_DAY, true);
        $partLabel = ['morning' => 'Mornings', 'afternoon' => 'Afternoons'][$part] ?? ($this->day_start
            ? substr($this->day_start, 0, 5) . '–' . substr($this->day_end, 0, 5)
            : 'Any time');
        $from = date('D j M', strtotime($this->from_date));
        $to = date('D j M', strtotime($this->to_date));
        $range = $from === $to ? $from : "$from – $to";

        if ($this->wantsEarlier() && $this->appointment) {
            $when = date('D j M \a\t H:i', strtotime($this->appointment->date . ' ' . $this->appointment->time));
            return "Earlier than {$when}" . ($part !== 'any' && $part !== false ? ' (' . strtolower($partLabel) . ')' : '');
        }

        return "{$partLabel}, {$range}";
    }

    /**
     * The patient's link, or null when no public address is known (a console run without
     * APP_PUBLIC_URL).
     */
    public function getUrl()
    {
        $sig = $this->signature();
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');
        if ($base !== '') {
            return $base . '/waitlist/' . $this->id . '/' . $sig;
        }
        if (Yii::$app instanceof \yii\web\Application) {
            return Url::to(['/waitlist/offer', 'id' => $this->id, 'sig' => $sig], true);
        }
        Yii::warning('Set APP_PUBLIC_URL so waiting-list notices sent by the queue worker can include their link.', __METHOD__);

        return null;
    }

    public function signature()
    {
        $payload = implode('|', ['waitlist', $this->id, $this->patient_id, $this->created_at]);

        return substr(hash_hmac('sha256', $payload, self::key()), 0, 32);
    }

    /**
     * @return static|null the entry when the signature matches it
     */
    public static function verify($id, $signature)
    {
        $entry = static::findOne((int) $id);
        if ($entry === null || !is_string($signature)) {
            return null;
        }

        return hash_equals($entry->signature(), $signature) ? $entry : null;
    }

    /** The same key as self-service links (SELF_SERVICE_KEY, else derived from the database). */
    private static function key()
    {
        $key = (string) (Yii::$app->params['selfService.key'] ?? '');
        if ($key !== '') {
            return $key;
        }

        return hash('sha256', 'self-service|' . Yii::$app->db->dsn . '|' . Yii::$app->db->password);
    }
}
