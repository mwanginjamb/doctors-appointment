<?php

namespace frontend\services;

use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use frontend\models\Appointments;
use Yii;

/**
 * Tells the other side when an appointment is cancelled or moved: if the clinic changed it
 * (consultant, front desk, admin, or a background job), the patient; if the patient changed it,
 * the consultant. By email where there is an address and WhatsApp where there is a phone.
 *
 * The cancellation note is internal and is not included.
 */
class AppointmentChangeNotifier
{
    const CANCELLED = 'cancelled';
    const MOVED = 'moved';

    /**
     * @param string $change self::CANCELLED or self::MOVED
     * @param string|null $oldDate Y-m-d before a move
     * @param string|null $oldTime H:i:s before a move
     * @return string[] channels queued, e.g. ['email', 'whatsapp']; empty when there was no contact
     */
    public static function notify(Appointments $appointment, $change, $oldDate = null, $oldTime = null)
    {
        $consultant = $appointment->consultant;
        $patient = $appointment->patient;
        $doctor = $consultant->names ?? 'your doctor';
        // F8: the facility it is at, with address and map link (the clinic's name for older ones)
        $place = $appointment->getPlaceText();

        $user = Yii::$app->get('user', false);
        $actorId = $user && !$user->isGuest ? (int) $user->id : null;
        // The patient: signed in as themselves, or acting through their self-service link
        $byPatient = $appointment->changedByPatient || ($actorId !== null && $actorId === (int) $appointment->patient_id);

        $when = self::when($appointment->date, $appointment->time);
        $was = $oldDate || $oldTime
            ? self::when($oldDate ?: $appointment->date, $oldTime ?: $appointment->time)
            : $when;
        $patientName = $patient->full_name ?? 'The patient';

        if ($byPatient) {
            // The patient changed it: tell the consultant
            $name = $doctor;
            $email = $consultant->consultant_email ?? null;
            $phone = $consultant->consultant_phone_number ?? null;
            $subject = $change === self::CANCELLED ? "Appointment cancelled by {$patientName}" : "Appointment moved by {$patientName}";
            $where = $appointment->facility ? " at {$appointment->facility->name}" : '';
            $text = $change === self::CANCELLED
                ? "Hello {$doctor},\n\n{$patientName} has cancelled their appointment{$where} on {$when}. The slot is free again."
                : "Hello {$doctor},\n\n{$patientName} has moved their appointment{$where} from {$was} to {$when}.";
        } else {
            // The clinic changed it: tell the patient
            $name = $patientName;
            $email = $patient->email ?? null;
            // F11: WhatsApp only with the patient's consent
            $phone = $patient && \common\models\Consent::remindersAllowed($patient->id) ? ($patient->phone_number ?? null) : null;
            $subject = $change === self::CANCELLED ? "Your appointment with {$doctor} is cancelled" : "Your appointment with {$doctor} has moved";
            $text = $change === self::CANCELLED
                ? "Hello {$patientName},\n\nYour appointment with {$doctor} at {$place} on {$when} has been cancelled. "
                    . 'Please contact the clinic or book a new time.'
                : "Hello {$patientName},\n\nYour appointment with {$doctor} has moved from {$was} to {$when}, at {$place}. "
                    . 'If the new time does not suit you, please contact the clinic.';
            // Moved: offer the self-service link for what the patient can still do (confirm the
            // new time, or move or cancel it outside the cut-off)
            $note = $change === self::MOVED ? SelfServiceLink::messageNote($appointment) : '';
            if ($note !== '') {
                $text .= "\n\n" . ltrim($note);
            }
        }

        $channels = [];
        if ($email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $email,
                'toName' => $name,
                'subject' => $subject,
                'text' => $text,
            ]));
            $channels[] = 'email';
        }
        if ($phone) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $phone, 'text' => $text]));
            $channels[] = 'whatsapp';
        }
        if (!$channels) {
            Yii::info("No email or phone to tell anyone that appointment #{$appointment->id} was {$change}.", 'notifications');
        }

        return $channels;
    }

    private static function when($date, $time)
    {
        return date('l j F Y \a\t H:i', strtotime($date . ' ' . $time));
    }
}
