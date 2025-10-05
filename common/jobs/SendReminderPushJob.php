<?php
// Push Notification Reminder Job
// File: common/jobs/SendReminderPushJob.php

namespace common\jobs;

use frontend\models\Appointments;
use frontend\models\AppointmentNotifications;
use frontend\models\Consultant;
use frontend\models\NotificationSchedules;
use frontend\models\UserProfile;
use yii\base\BaseObject;
use Yii;

class SendReminderPushJob extends BaseObject implements \yii\queue\JobInterface
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

            $notification = $this->getPushNotificationData($appointment);

            // Send push notifications to recipients
            foreach ($recipients as $deviceToken => $name) {
                $personalizedNotification = $notification;
                $personalizedNotification['body'] = str_replace('{recipient_name}', $name, $notification['body']);
                $this->sendPushNotification($deviceToken, $personalizedNotification);
            }

        } catch (\Exception $e) {
            // Mark notification as failed if we have notificationId
            if ($this->notificationId) {
                $notification = AppointmentNotifications::findOne($this->notificationId);
                if ($notification) {
                    $notification->markAsFailed($e->getMessage());
                }
            }

            Yii::error('Push notification job failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get push notification recipients based on recipient type
     */
    private function getRecipients($appointment)
    {
        $recipients = [];

        switch ($this->recipientType) {
            case NotificationSchedules::METHOD_PATIENT:
                if ($appointment->patient && $appointment->patient->device_token) {
                    $recipients[$appointment->patient->device_token] = $appointment->patient->full_name ?? 'Patient';
                }
                break;

            case NotificationSchedules::METHOD_CONSULTANT:
                if ($appointment->consultant && $appointment->consultant->device_token) {
                    $recipients[$appointment->consultant->device_token] = $appointment->consultant->names ?? 'Doctor';
                }
                break;

            case NotificationSchedules::METHOD_BOTH:
            default:
                if ($appointment->patient && $appointment->patient->device_token) {
                    $recipients[$appointment->patient->device_token] = $appointment->patient->full_name ?? 'Patient';
                }
                if ($appointment->consultant && $appointment->consultant->device_token) {
                    $recipients[$appointment->consultant->device_token] = $appointment->consultant->names ?? 'Doctor';
                }
                break;
        }

        return $recipients;
    }

    /**
     * Get push notification data
     */
    private function getPushNotificationData($appointment)
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

        return [
            'title' => 'Appointment Reminder',
            'body' => "Hello {recipient_name}, your appointment is in {$timeUnit} on {$appointmentDate} at {$appointmentTime}",
            'data' => [
                'appointment_id' => $appointment->id,
                'type' => 'appointment_reminder',
                'minutes_before' => $minutes
            ]
        ];
    }

    /**
     * Send push notification using Firebase Cloud Messaging
     */
    private function sendPushNotification($deviceToken, $notification)
    {
        $config = Yii::$app->params['push']['fcm'] ?? [];

        if (empty($config['server_key'])) {
            throw new \Exception('Firebase Cloud Messaging configuration missing');
        }

        $headers = [
            'Authorization: key=' . $config['server_key'],
            'Content-Type: application/json',
        ];

        $payload = [
            'to' => $deviceToken,
            'notification' => [
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => 'ic_notification',
                'sound' => 'default'
            ],
            'data' => $notification['data']
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('Failed to send push notification: ' . $result);
        }

        $response = json_decode($result, true);
        // Handle invalid tokens
        if (isset($response['results'][0]['error'])) {
            $error = $response['results'][0]['error'];

            // Token is invalid or unregistered - clear it from database
            if (in_array($error, ['NotRegistered', 'InvalidRegistration', 'MismatchSenderId'])) {
                Yii::warning("Invalid token detected: {$deviceToken}. Error: {$error}", __METHOD__);
                $this->clearInvalidToken($deviceToken);
            }

            throw new \Exception('Push notification failed: ' . $error);
        }

        if (isset($response['failure']) && $response['failure'] > 0) {
            throw new \Exception('Push notification failed: ' . json_encode($response));
        }
    }


    /**
     * Clear invalid token from database
     */
    private function clearInvalidToken($deviceToken)
    {
        try {
            // Clear from patients table
            $patientsUpdated = UserProfile::updateAll(
                [
                    'device_token' => null,
                    'device_type' => null,
                    'token_updated_at' => null
                ],
                ['device_token' => $deviceToken]
            );

            // Clear from consultants table
            $consultantsUpdated = Consultant::updateAll(
                [
                    'device_token' => null,
                    'device_type' => null,
                    'token_updated_at' => null
                ],
                ['device_token' => $deviceToken]
            );

            Yii::info(
                "Cleared invalid token. Patients affected: {$patientsUpdated}, Consultants affected: {$consultantsUpdated}",
                __METHOD__
            );

        } catch (\Exception $e) {
            Yii::error("Failed to clear invalid token: " . $e->getMessage(), __METHOD__);
        }
    }
}