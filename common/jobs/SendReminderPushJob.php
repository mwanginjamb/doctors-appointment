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
            $errors = [];
            foreach ($recipients as $deviceToken => $name) {
                $personalizedNotification = $notification;
                $personalizedNotification['body'] = str_replace('{recipient_name}', $name, $notification['body']);
                try {
                    $this->sendPushNotification($deviceToken, $personalizedNotification);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if (count($errors) === count($recipients)) {
                throw new \Exception(implode('; ', $errors));
            }

            AppointmentNotifications::markAsSentById($this->notificationId);
        } catch (\Exception $e) {
            AppointmentNotifications::markAsFailedById($this->notificationId, $e->getMessage());
            Yii::error('Push notification job failed: ' . $e->getMessage(), 'notifications');
            throw $e;
        }
    }

    /**
     * Get push notification recipients based on recipient type
     */
    private function getRecipients($appointment)
    {
        $recipients = [];
        $toPatient = $this->recipientType !== NotificationSchedules::METHOD_CONSULTANT;
        $toConsultant = $this->recipientType !== NotificationSchedules::METHOD_PATIENT;

        // Patients' device tokens live on their profile, not on the user record
        if ($toPatient && $appointment->patientProfile && $appointment->patientProfile->device_token) {
            $recipients[$appointment->patientProfile->device_token] = $appointment->patient->full_name ?? 'Patient';
        }
        if ($toConsultant && $appointment->consultant && $appointment->consultant->device_token) {
            $recipients[$appointment->consultant->device_token] = $appointment->consultant->names ?? 'Doctor';
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
            'body' => "Hello {recipient_name}, your appointment is in {$timeUnit} on {$appointmentDate} at {$appointmentTime}"
                . ($appointment->facility ? " at {$appointment->facility->name}" : ''),
            'data' => [
                'appointment_id' => $appointment->id,
                'type' => 'appointment_reminder',
                'minutes_before' => $minutes
            ]
        ];
    }

    /**
     * Send push notification using the Firebase Cloud Messaging HTTP v1 API
     * (the legacy /fcm/send endpoint and server keys were shut down by Google in 2024).
     */
    private function sendPushNotification($deviceToken, $notification)
    {
        $credentials = $this->getServiceAccount();
        $projectId = Yii::$app->params['push']['fcm']['project_id'] ?? null;
        if (empty($projectId)) {
            $projectId = $credentials['project_id'];
        }

        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                ],
                // FCM v1 only accepts string values in data
                'data' => array_map('strval', $notification['data']),
            ],
        ];

        [$httpCode, $response] = $this->postJson(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            json_encode($payload),
            ['Authorization: Bearer ' . $this->getAccessToken($credentials), 'Content-Type: application/json']
        );

        if ($httpCode === 200) {
            return;
        }

        $error = json_decode($response, true)['error'] ?? [];
        $errorCodes = array_column($error['details'] ?? [], 'errorCode');
        // Token is invalid or unregistered - clear it from database
        if (
            in_array('UNREGISTERED', $errorCodes, true)
            || (($error['status'] ?? '') === 'INVALID_ARGUMENT' && stripos($error['message'] ?? '', 'registration token') !== false)
        ) {
            Yii::warning('Invalid push token detected. Error: ' . ($error['message'] ?? $httpCode), __METHOD__);
            $this->clearInvalidToken($deviceToken);
        }

        throw new \Exception('Push notification failed (HTTP ' . $httpCode . '): ' . ($error['message'] ?? $response));
    }

    /**
     * Load the Firebase service-account key configured in params['push']['fcm']['credentials_file'].
     */
    private function getServiceAccount()
    {
        $file = Yii::$app->params['push']['fcm']['credentials_file'] ?? null;
        $file = $file ? Yii::getAlias($file) : null;
        if (!$file || !is_readable($file)) {
            throw new \Exception('Firebase service-account file is not configured (FCM_CREDENTIALS_FILE)');
        }

        $credentials = json_decode(file_get_contents($file), true);
        if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new \Exception('Firebase service-account file is invalid');
        }

        return $credentials;
    }

    /**
     * Exchange a signed service-account JWT for an OAuth access token, cached until shortly before expiry.
     */
    private function getAccessToken(array $credentials)
    {
        $cacheKey = 'fcm_access_token_' . md5($credentials['client_email']);
        $token = Yii::$app->cache->get($cacheKey);
        if ($token) {
            return $token;
        }

        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];
        if (!openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \Exception('Could not sign the Firebase access token request');
        }
        $segments[] = $this->base64UrlEncode($signature);

        [$httpCode, $response] = $this->postJson($tokenUri, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments),
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new \Exception('Could not obtain a Firebase access token: ' . $response);
        }

        Yii::$app->cache->set($cacheKey, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 300));

        return $data['access_token'];
    }

    private function postJson($url, $body, array $headers)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new \Exception('HTTP request to ' . $url . ' failed: ' . $error);
        }

        return [$httpCode, $result];
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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