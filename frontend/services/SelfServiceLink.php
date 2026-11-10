<?php

namespace frontend\services;

use frontend\models\Appointments;
use Yii;
use yii\helpers\Url;

/**
 * Signed links that let a patient reschedule or cancel their own appointment without signing in:
 * /manage/<appointment id>/<signature>.
 *
 * The signature is an HMAC of the appointment's id, patient, date and time, so moving the
 * appointment makes older links stop working, and nothing is stored. Changes are allowed until
 * selfService.cutoffHours before the appointment.
 */
class SelfServiceLink
{
    /**
     * @return string|null the link, or null when it cannot be built (no public address known)
     */
    public static function url(Appointments $appointment)
    {
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');
        $path = '/manage/' . $appointment->id . '/' . self::signature($appointment);
        if ($base !== '') {
            return $base . $path;
        }
        if (Yii::$app instanceof \yii\web\Application) {
            return Url::to(['/manage/index', 'id' => $appointment->id, 'sig' => self::signature($appointment)], true);
        }
        Yii::warning('Set APP_PUBLIC_URL so reminders sent by the queue worker can include self-service links.', __METHOD__);

        return null;
    }

    /**
     * The link when the patient can still use it (outside the cut-off), else null. For messages:
     * there is no point sending a link that would only say "too late".
     */
    public static function urlIfChangeable(Appointments $appointment)
    {
        return self::isChangeable($appointment) ? self::url($appointment) : null;
    }

    /**
     * @return Appointments|null the appointment when the signature matches it as it is now
     */
    public static function verify($id, $signature)
    {
        $appointment = Appointments::findOne((int) $id);
        if ($appointment === null || !is_string($signature)) {
            return null;
        }

        return hash_equals(self::signature($appointment), $signature) ? $appointment : null;
    }

    /**
     * Still scheduled or confirmed, and more than the cut-off away.
     */
    public static function isChangeable(Appointments $appointment)
    {
        $active = $appointment->status === null
            || in_array($appointment->status, [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED], true);

        return $active && strtotime($appointment->date . ' ' . $appointment->time) > time() + self::cutoffHours() * 3600;
    }

    public static function cutoffHours()
    {
        return max(0, (int) (Yii::$app->params['selfService.cutoffHours'] ?? 24));
    }

    public static function signature(Appointments $appointment)
    {
        $payload = implode('|', [$appointment->id, $appointment->patient_id, $appointment->date, substr((string) $appointment->time, 0, 5)]);

        return substr(hash_hmac('sha256', $payload, self::key()), 0, 32);
    }

    /**
     * SELF_SERVICE_KEY; without it, a key derived from the database credentials, which both the
     * website and the queue worker share (set SELF_SERVICE_KEY in production).
     */
    private static function key()
    {
        $key = (string) (Yii::$app->params['selfService.key'] ?? '');
        if ($key !== '') {
            return $key;
        }

        return hash('sha256', 'self-service|' . Yii::$app->db->dsn . '|' . Yii::$app->db->password);
    }
}
