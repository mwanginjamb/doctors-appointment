<?php

namespace frontend\services;

use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantRating;
use Yii;

/**
 * F30: patients rate the consultant after a visit.
 *
 * When an appointment is recorded as attended, the patient is sent (about 3 hours later, by
 * email and WhatsApp with consent) a signed link, /rate/<appointment id>/<signature>, to rate
 * the consultant 1 to 5 for that visit, with an optional private comment. They can change it for
 * 30 days. One rating per appointment; a visit corrected to a no-show withdraws its rating and
 * closes the link.
 *
 * The consultant sees their ratings anonymously (stars, comments, dates); platform admins see
 * who gave each. The average and count show on the public profile from 5 ratings.
 */
class Ratings
{
    /** Days the link stays open after the visit was recorded as attended. */
    const DAYS_OPEN = 30;
    /** Ratings needed before the average shows on the public profile. */
    const PUBLIC_MIN = 5;
    /** Seconds after the outcome before the link is sent: not while the patient is still at the clinic. */
    const SEND_DELAY = 10800;

    /** Called when attended or no-show is recorded (or corrected). */
    public static function onOutcome(Appointments $appointment)
    {
        if ($appointment->status === Appointments::STATUS_NO_SHOW) {
            ConsultantRating::updateAll(['withdrawn' => 1, 'updated_at' => time()], ['appointment_id' => $appointment->id]);

            return;
        }
        if ($appointment->status !== Appointments::STATUS_COMPLETED) {
            return;
        }
        // Corrected back to attended: the rating counts again
        ConsultantRating::updateAll(['withdrawn' => 0, 'updated_at' => time()], ['appointment_id' => $appointment->id, 'withdrawn' => 1]);
        if ($appointment->rating_requested_at) {
            return; // sent once
        }
        self::send($appointment);
        $appointment->updateAttributes(['rating_requested_at' => time()]);
    }

    public static function signature(Appointments $appointment)
    {
        return SelfServiceLink::sign('rate|' . $appointment->id . '|' . $appointment->patient_id);
    }

    /** The rating page, absolute when APP_PUBLIC_URL is set (the queue worker sends it). */
    public static function url(Appointments $appointment)
    {
        $path = '/rate/' . $appointment->id . '/' . self::signature($appointment);
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');

        return $base . $path;
    }

    /** The appointment for a link, or null when the signature does not match. */
    public static function verify($id, $sig)
    {
        $appointment = Appointments::findOne((int) $id);

        return $appointment !== null && hash_equals(self::signature($appointment), (string) $sig) ? $appointment : null;
    }

    /** Whether the patient may (still) rate this visit: attended, and within the days it is open. */
    public static function isOpen(Appointments $appointment)
    {
        if ($appointment->status !== Appointments::STATUS_COMPLETED) {
            return false;
        }
        $from = $appointment->outcome_at ?: strtotime($appointment->date . ' ' . $appointment->time);

        return time() <= $from + self::DAYS_OPEN * 86400;
    }

    /** The visit's rating, or null. */
    public static function forAppointment(Appointments $appointment)
    {
        return ConsultantRating::findOne(['appointment_id' => $appointment->id]);
    }

    /**
     * Saves (or changes) the patient's rating of this visit.
     *
     * @return ConsultantRating with errors when not saved
     */
    public static function rate(Appointments $appointment, $stars, $comment)
    {
        $consultant = $appointment->consultant;
        $rating = self::forAppointment($appointment) ?? new ConsultantRating([
            'appointment_id' => $appointment->id,
            'consultant_id' => $consultant->id ?? 0,
            'patient_id' => $appointment->patient_id,
            'facility_id' => $appointment->facility_id,
        ]);
        $rating->stars = ctype_digit((string) $stars) ? (int) $stars : null;
        $rating->comment = mb_substr((string) $comment, 0, 1000);
        $rating->withdrawn = 0;
        $rating->save();

        return $rating;
    }

    /** @return array ['average' => float|null, 'count' => int, 'stars' => [5 => n, …, 1 => n]] */
    public static function summary(Consultant $consultant)
    {
        $rows = ConsultantRating::find()->select(['stars', 'n' => 'COUNT(*)'])
            ->where(['consultant_id' => $consultant->id, 'withdrawn' => 0])->groupBy('stars')->asArray()->all();
        $byStars = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $count = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $byStars[(int) $row['stars']] = (int) $row['n'];
            $count += (int) $row['n'];
            $sum += (int) $row['stars'] * (int) $row['n'];
        }

        return ['average' => $count ? $sum / $count : null, 'count' => $count, 'stars' => $byStars];
    }

    /** The summary for the public profile, or null until there are enough ratings. */
    public static function publicSummary(Consultant $consultant)
    {
        $summary = self::summary($consultant);

        return $summary['count'] >= self::PUBLIC_MIN ? $summary : null;
    }

    private static function send(Appointments $appointment)
    {
        $patient = $appointment->patient;
        if ($patient === null) {
            return;
        }
        $doctor = $appointment->consultant->names ?? 'your doctor';
        $when = date('D j M', strtotime($appointment->date));
        $link = self::url($appointment);
        $text = 'Hello ' . ($patient->full_name ?: 'there') . ",\n\nHow was your visit to {$doctor} on {$when}? "
            . 'Rate it from 1 to 5 stars; it takes a few seconds and helps other patients.';
        $queue = Yii::$app->queue;
        if ($patient->email) {
            $queue->delay(self::SEND_DELAY)->push(new SendEmailMessageJob([
                'to' => $patient->email, 'toName' => $patient->full_name ?: null,
                'subject' => "How was your visit to {$doctor}?", 'text' => $text,
                'linkUrl' => $link, 'linkLabel' => 'Rate your visit',
            ]));
        }
        if ($patient->phone_number && \common\models\Consent::remindersAllowed($patient->id)) {
            $queue->delay(self::SEND_DELAY)->push(new SendWhatsappTextJob(['phone' => $patient->phone_number, 'text' => $text . "\n\n" . $link]));
        }
    }
}
