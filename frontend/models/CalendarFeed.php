<?php

namespace frontend\models;

use Yii;
use yii\base\BaseObject;

/**
 * A consultant's calendar feed (F9): an iCal address their own calendar (Google, Apple, Outlook)
 * subscribes to, showing their appointments and one-off sessions.
 *
 * The address carries a secret; only its sha256 is stored (consultant.calendar_feed_hash), so the
 * address is shown once when made, and making a new one stops the old one. Events say
 * "Appointment", where and when: never the patient's name or the reason for the visit, because
 * the consultant's calendar provider keeps everything the feed sends.
 */
class CalendarFeed extends BaseObject
{
    /** Days back and ahead the feed covers */
    const DAYS_BACK = 30;
    const DAYS_AHEAD = 180;

    /** @var Consultant */
    public $consultant;

    /**
     * Makes a new secret for the consultant, stopping any earlier address.
     *
     * @return string the feed's full address, to show once
     */
    public static function regenerate(Consultant $consultant)
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $consultant->updateAttributes(['calendar_feed_hash' => hash('sha256', $token)]);
        \common\components\Audit::log(\common\models\AuditLog::ACTION_UPDATED, 'consultant', $consultant->id, ['calendar_feed' => [null, '[new address]']]);

        return self::url($token);
    }

    /** Stops the consultant's feed. */
    public static function revoke(Consultant $consultant)
    {
        $consultant->updateAttributes(['calendar_feed_hash' => null]);
        \common\components\Audit::log(\common\models\AuditLog::ACTION_UPDATED, 'consultant', $consultant->id, ['calendar_feed' => ['[address]', null]]);
    }

    /**
     * The consultant whose feed $token opens, or null.
     */
    public static function findConsultant($token)
    {
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        return Consultant::findOne(['calendar_feed_hash' => hash('sha256', $token)]);
    }

    public static function url($token)
    {
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');

        return $base !== ''
            ? $base . '/calendar/' . $token . '.ics'
            : \yii\helpers\Url::to(['/calendar-feed/ics', 'token' => $token], true);
    }

    /**
     * The feed as an iCalendar document (RFC 5545), times in UTC.
     */
    public function render()
    {
        $consultant = $this->consultant;
        $from = date('Y-m-d', strtotime('-' . self::DAYS_BACK . ' days'));
        $to = date('Y-m-d', strtotime('+' . self::DAYS_AHEAD . ' days'));
        $host = parse_url((string) (Yii::$app->params['app.publicUrl'] ?? ''), PHP_URL_HOST) ?: 'doctor-appointments';
        $stamp = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . $host . '//Appointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text(Yii::$app->name . ': ' . $consultant->names),
            'X-PUBLISHED-TTL:PT1H',
        ];

        $appointments = Appointments::find()->orderBy(['date' => SORT_ASC, 'time' => SORT_ASC])->with('facility')
            ->where(['consultant_id' => $consultant->user_id])
            ->andWhere(['between', 'date', $from, $to])
            ->andWhere(['or', ['status' => null], ['<>', 'status', Appointments::STATUS_CANCELLED]])
            ->all();
        foreach ($appointments as $appointment) {
            $start = strtotime($appointment->date . ' ' . $appointment->time);
            $place = $appointment->facility;
            $lines = array_merge($lines, $this->event(
                'appointment-' . $appointment->id . '@' . $host,
                $start,
                $start + $appointment->getDurationMinutes() * 60,
                'Appointment' . ($place ? ' · ' . $place->name : ''),
                $place ? $place->getDirections() : null,
                $stamp,
                $appointment->status === Appointments::STATUS_NO_SHOW ? 'No-show' : null
            ));
        }

        $sessions = ConsultantSession::find()->with('facility')
            ->where(['consultant_id' => $consultant->id])
            ->andWhere(['between', 'date', $from, $to])
            ->orderBy(['date' => SORT_ASC, 'start_time' => SORT_ASC])->all();
        foreach ($sessions as $session) {
            $lines = array_merge($lines, $this->event(
                'session-' . $session->id . '@' . $host,
                strtotime($session->date . ' ' . $session->start_time),
                strtotime($session->date . ' ' . $session->end_time),
                'Session · ' . ($session->facility->name ?? '') . ($session->note ? ' (' . $session->note . ')' : ''),
                $session->facility ? $session->facility->getDirections() : null,
                $stamp,
                null,
                true
            ));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private function event($uid, $start, $end, $summary, $location, $stamp, $description = null, $free = false)
    {
        $event = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $stamp,
            'DTSTART:' . gmdate('Ymd\THis\Z', $start),
            'DTEND:' . gmdate('Ymd\THis\Z', $end),
            'SUMMARY:' . self::text($summary),
        ];
        if ($location) {
            $event[] = 'LOCATION:' . self::text($location);
        }
        if ($description) {
            $event[] = 'DESCRIPTION:' . self::text($description);
        }
        // A session is time set aside, not itself busy: its appointments are the busy times
        $event[] = 'TRANSP:' . ($free ? 'TRANSPARENT' : 'OPAQUE');
        $event[] = 'END:VEVENT';

        return $event;
    }

    /** Escapes a text value (RFC 5545 3.3.11). */
    private static function text($value)
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $value);
    }

    /** Folds a content line at 75 octets (RFC 5545 3.1), without splitting a UTF-8 character. */
    private static function fold($line)
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }

        return $out . $line;
    }
}
