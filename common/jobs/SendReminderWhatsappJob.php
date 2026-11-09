<?php

// WhatsApp Reminder Job
// File: /commonjobs/SendReminderWhatsappJob.php

namespace common\jobs;

use frontend\models\Appointments;
use frontend\models\AppointmentNotifications;
use frontend\models\NotificationSchedules;
use yii\base\BaseObject;
use Yii;

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

            // Patients and consultants get differently worded messages
            foreach ($recipients as $phone => $role) {
                $this->sendWhatsapp($phone, $this->getWhatsappMessage($appointment, $role), $appointment);
            }

            AppointmentNotifications::markAsSentById($this->notificationId);
        } catch (\Exception $e) {
            AppointmentNotifications::markAsFailedById($this->notificationId, $e->getMessage());
            Yii::error('WhatsApp reminder job failed: ' . $e->getMessage(), 'notifications');
            throw $e;
        }
    }

    /**
     * Get WhatsApp recipients based on recipient type, as phone => role (patient|consultant)
     */
    private function getRecipients($appointment)
    {
        $toPatient = $this->recipientType !== NotificationSchedules::METHOD_CONSULTANT;
        $toConsultant = $this->recipientType !== NotificationSchedules::METHOD_PATIENT;

        $recipients = [];

        if ($toPatient && $appointment->patient && $appointment->patient->phone_number) {
            $recipients[$appointment->patient->phone_number] = 'patient';
        }
        if ($toConsultant && $appointment->consultant && $appointment->consultant->consultant_phone_number) {
            $recipients[$appointment->consultant->consultant_phone_number] = 'consultant';
        }

        return $recipients;
    }

    /**
     * Get the WhatsApp message worded for the recipient's role
     */
    private function getWhatsappMessage($appointment, $role)
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

        if ($role === 'consultant') {
            return "Hello {$consultantName}, this is a reminder that you have an appointment with {$patientName} scheduled for {$appointmentDate} at {$appointmentTime} (in {$timeUnit}).";
        }

        return "Hello {$patientName}, this is a reminder that your appointment with {$consultantName} is scheduled for {$appointmentDate} at {$appointmentTime} (in {$timeUnit}). Please arrive 15 minutes early.";
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

        // WAHA's reply_to quotes an existing message id, so it is not used for reminders
        $payload = [
            'chatId' => $chatId,
            'text' => $message,
            'session' => $session,
            'linkPreview' => false,
            'linkPreviewHighQuality' => false
        ];

        $this->sendWahaRequest($endpoint, $payload, $wahaConfig['api_key'] ?? null);
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
     * Send request to WAHA API
     */
    private function sendWahaRequest($endpoint, $payload, $apiKey = null)
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if ($apiKey) {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
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
            throw new \Exception($errorMessage);
        }

        // Log successful send
        Yii::info('WhatsApp message sent successfully: ' . $result, 'notifications');

        return json_decode($result, true);
    }
}