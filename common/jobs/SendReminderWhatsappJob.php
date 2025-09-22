<?php

// WhatsApp Reminder Job
// File: console/jobs/SendReminderWhatsappJob.php

namespace console\jobs;

use frontend\models\Appointments;
use frontend\models\AppointmentNotifications;
use frontend\models\NotificationSchedules;
use yii\base\BaseObject;
use Yii;
use yii\helpers\VarDumper;

class SendReminderWhatsappJob extends BaseObject implements \yii\queue\JobInterface
{
    public $appointmentId;
    public $reminderType;
    public $notificationId;
    public $recipientType;

    public function execute($queue)
    {
        $appointment = Appointments::findOne($this->appointmentId);

        if (!$appointment) {
            return;
        }

        try {
            $recipients = $this->getRecipients($appointment);

            if (empty($recipients)) {
                throw new \Exception('No valid recipients found');
            }

            $message = $this->getWhatsappMessage($appointment);

            // Send WhatsApp to recipients
            foreach ($recipients as $phone => $name) {
                $personalizedMessage = str_replace('{recipient_name}', $name, $message);
                $this->sendWhatsapp($phone, $personalizedMessage, $appointment);
            }

        } catch (\Exception $e) {
            // Mark notification as failed if we have notificationId
            if ($this->notificationId) {
                $notification = AppointmentNotifications::findOne($this->notificationId);
                if ($notification) {
                    $notification->markAsFailed($e->getMessage());
                }
            }

            Yii::error('WhatsApp reminder job failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get WhatsApp recipients based on recipient type
     */
    private function getRecipients($appointment)
    {
        $recipients = [];

        switch ($this->recipientType) {
            case NotificationSchedules::METHOD_PATIENT:
                if ($appointment->patient && $appointment->patient->phone_number) {
                    $recipients[$appointment->patient->phone_number] = $appointment->patient->full_name ?? 'Patient';
                }
                break;

            case NotificationSchedules::METHOD_CONSULTANT:
                if ($appointment->consultant && $appointment->consultant->consultant_phone_number) {
                    $recipients[$appointment->consultant->consultant_phone_number] = $appointment->consultant->names ?? 'Doctor';
                }
                break;

            case NotificationSchedules::METHOD_BOTH:
            default:
                if ($appointment->patient && $appointment->patient->phone_number) {
                    $recipients[$appointment->patient->phone_number] = $appointment->patient->full_name ?? 'Patient';
                }
                if ($appointment->consultant && $appointment->consultant->consultant_phone_number) {
                    $recipients[$appointment->consultant->consultant_phone_number] = $appointment->consultant->names ?? 'Doctor';
                }
                break;
        }

        return $recipients;
    }

    /**
     * Get WhatsApp message
     */
    private function getWhatsappMessage($appointment)
    {
        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        if ($minutes >= 60) {
            $hours = $minutes / 60;
            $timeUnit = $hours == 1 ? '1 hour' : $hours . ' hours';
        } else {
            $timeUnit = $minutes . ' minutes';
        }

        $appointmentDate = date('M j, Y', strtotime($appointment->date));
        $appointmentTime = date('g:i A', strtotime($appointment->time));

        $patientName = $appointment->patient->full_name ?? 'Patient';
        $consultantName = $appointment->consultant->names ?? 'Doctor';

        return "Hello {recipient_name}, this is a reminder that your appointment with {$consultantName} is scheduled for {$appointmentDate} at {$appointmentTime} (in {$timeUnit}). Please arrive 15 minutes early.";
    }

    /**
     * Send WhatsApp message using WAHA API
     */
    private function sendWhatsapp($phone, $message, $appointment)
    {
        $wahaConfig = Yii::$app->params['whatsapp'] ?? [];

        if (empty($wahaConfig['endpoint'])) {
            throw new \Exception('WAHA WhatsApp configuration missing');
        }

        $endpoint = rtrim($wahaConfig['endpoint'], '/') . '/api/sendText';
        $session = $wahaConfig['session'] ?? 'default';

        // Format phone number for WhatsApp (add @c.us suffix if not present)
        $chatId = $this->formatPhoneNumber($phone);

        // Get reply_to number (consultant's number when sending to patient, patient's number when sending to consultant)
        $replyTo = $this->getReplyToNumber($phone, $appointment);

        $payload = [
            'chatId' => $chatId,
            'text' => $message,
            'session' => $session,
            'linkPreview' => false,
            'linkPreviewHighQuality' => false
        ];

        // Add reply_to if available
        if ($replyTo) {
            $payload['reply_to'] = $replyTo;
        }

        $this->sendWahaRequest($endpoint, $payload);
    }

    /**
     * Format phone number for WhatsApp
     */
    private function formatPhoneNumber($phone)
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Add country code if not present (assuming Kenya +254 as default)
        if (!str_starts_with($phone, '254') && str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '254') && strlen($phone) === 9) {
            $phone = '254' . $phone;
        }

        // Add @c.us suffix for WhatsApp format
        return $phone . '@c.us';
    }

    /**
     * Get reply_to number based on recipient
     */
    private function getReplyToNumber($recipientPhone, $appointment)
    {
        // Clean recipient phone for comparison
        $cleanRecipientPhone = preg_replace('/[^0-9]/', '', $recipientPhone);

        // If recipient is patient, reply_to should be consultant
        if ($appointment->patient && $appointment->patient->phone_number) {
            $cleanPatientPhone = preg_replace('/[^0-9]/', '', $appointment->patient->phone);
            if (str_contains($cleanPatientPhone, substr($cleanRecipientPhone, -9))) {
                return $appointment->consultant && $appointment->consultant->consultant_phone_number
                    ? $this->formatPhoneNumber($appointment->consultant->consultant_phone_number)
                    : null;
            }
        }

        // If recipient is consultant, reply_to should be patient
        if ($appointment->consultant && $appointment->consultant->consultant_phone_number) {
            $cleanConsultantPhone = preg_replace('/[^0-9]/', '', $appointment->consultant->consultant_phone_number);
            if (str_contains($cleanConsultantPhone, substr($cleanRecipientPhone, -9))) {
                return $appointment->patient && $appointment->patient->phone_number
                    ? $this->formatPhoneNumber($appointment->patient->phone_number)
                    : null;
            }
        }

        return null;
    }

    /**
     * Send request to WAHA API
     */
    private function sendWahaRequest($endpoint, $payload)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // SSL configuration to avoid validation issues
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorMessage = 'Failed to send WhatsApp message. HTTP Code: ' . $httpCode;
            if ($result) {
                $decodedResult = json_decode($result, true);
                if (isset($decodedResult['message'])) {
                    $errorMessage .= '. Error: ' . $decodedResult['message'];
                } else {
                    $errorMessage .= '. Response: ' . $result;
                }
            }
            // throw new \Exception($errorMessage);
            Yii::error('WhatsApp message notification error: ' . VarDumper::dump($errorMessage, 10, true));
        }

        // Log successful send
        Yii::info('WhatsApp message sent successfully: ' . $result);

        return json_decode($result, true);
    }
}