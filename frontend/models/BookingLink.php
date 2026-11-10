<?php

namespace frontend\models;

use common\models\User;
use Yii;
use yii\db\ActiveRecord;
use yii\helpers\Url;

/**
 * A link a consultant or front desk sends to a potential patient ("remote walk-in") to book one of
 * the open slots in a short date range. The plain token only exists in the link itself; the table
 * keeps its SHA-256 hash. A link works until it expires (bookingLink.ttlMinutes after creation),
 * is used for a booking, or is revoked.
 *
 * @property int $id
 * @property string $token_hash
 * @property int $consultant_id consultant.id
 * @property string $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $recipient_email
 * @property string $from_date Y-m-d
 * @property string $to_date Y-m-d
 * @property string|null $day_start H:i:s
 * @property string|null $day_end H:i:s
 * @property int $expires_at
 * @property int|null $used_at
 * @property int|null $appointment_id
 * @property int|null $revoked_at
 * @property int|null $created_at
 * @property int|null $created_by
 */
class BookingLink extends ActiveRecord
{
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_USED = 'used';
    const STATUS_REVOKED = 'revoked';

    public static function tableName()
    {
        return '{{%booking_link}}';
    }

    /**
     * Creates a link and returns it with its plain token, which is never stored.
     *
     * @param array $attributes consultant_id, recipient_*, from_date, to_date, day_start, day_end
     * @return array [BookingLink, string token]
     */
    public static function issue(array $attributes)
    {
        $token = Yii::$app->security->generateRandomString(43); // [A-Za-z0-9_-], ~256 bits
        $link = new static($attributes);
        $link->token_hash = hash('sha256', $token);
        $link->created_at = time();
        $link->expires_at = time() + self::ttlMinutes() * 60;
        $user = Yii::$app->get('user', false);
        $link->created_by = $user && !$user->isGuest ? $user->id : null;
        $link->save(false);

        return [$link, $token];
    }

    public static function ttlMinutes()
    {
        return max(1, (int) (Yii::$app->params['bookingLink.ttlMinutes'] ?? 20));
    }

    /**
     * @return static|null the link for a token, whatever its status
     */
    public static function findByToken($token)
    {
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }

        return static::findOne(['token_hash' => hash('sha256', $token)]);
    }

    /**
     * The public address of the link: bookingLink.baseUrl when set, else this site's address.
     */
    public static function urlFor($token)
    {
        $base = rtrim((string) (Yii::$app->params['bookingLink.baseUrl'] ?? ''), '/');

        return $base !== '' ? $base . '/book/' . $token : Url::to(['/book/index', 'token' => $token], true);
    }

    public function getStatus()
    {
        if ($this->revoked_at) {
            return self::STATUS_REVOKED;
        }
        if ($this->used_at) {
            return self::STATUS_USED;
        }

        return $this->expires_at <= time() ? self::STATUS_EXPIRED : self::STATUS_ACTIVE;
    }

    public function isUsable()
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    public function getSender()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }

    /** @return string[] Y-m-d dates in the range */
    public function getDates()
    {
        $dates = [];
        for ($ts = strtotime($this->from_date); $ts <= strtotime($this->to_date); $ts = strtotime('+1 day', $ts)) {
            $dates[] = date('Y-m-d', $ts);
        }

        return $dates;
    }

    /**
     * Free start times the link offers: inside the consultant's opening hours and the link's daily
     * window, in the future, and clear of other appointments (each by its own length), time off and
     * public holidays. Slots step by the consultant's session length from each opening time.
     *
     * @return array Y-m-d => list of 'H:i' start times (dates without free slots have an empty list)
     */
    public function openSlots()
    {
        return (new SlotFinder([
            'consultant' => $this->consultant,
            'from' => $this->from_date,
            'to' => $this->to_date,
            'dayStart' => $this->day_start,
            'dayEnd' => $this->day_end,
        ]))->find();
    }

    /**
     * Whether $date $time is one of the slots the link offers right now.
     */
    public function offers($date, $time)
    {
        return in_array(substr((string) $time, 0, 5), $this->openSlots()[$date] ?? [], true);
    }
}
