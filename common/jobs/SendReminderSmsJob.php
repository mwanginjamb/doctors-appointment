<?php

// SMS Reminder Job
// File: common/jobs/SendReminderSmsJob.php

namespace common\jobs;

use frontend\models\Appointments;
use frontend\models\AppointmentNotifications;
use frontend\models\NotificationSchedules;
use yii\base\BaseObject;
use Yii;

class SendReminderSmsJob extends BaseObject implements \yii\queue\JobInterface
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

            $message = $this->getSmsMessage($appointment);

            // Send SMS to recipients
            foreach ($recipients as $phone => $name) {
                $personalizedMessage = str_replace('{recipient_name}', $name, $message);
                $this->sendSms($phone, $personalizedMessage);
            }

        } catch (\Exception $e) {
            // Mark notification as failed if we have notificationId
            if ($this->notificationId) {
                $notification = AppointmentNotifications::findOne($this->notificationId);
                if ($notification) {
                    $notification->markAsFailed($e->getMessage());
                }
            }

            Yii::error('SMS reminder job failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get SMS recipients based on recipient type
     */
    private function getRecipients($appointment)
    {
        $recipients = [];

        switch ($this->recipientType) {
            case NotificationSchedules::METHOD_PATIENT:
                if ($appointment->patient && $appointment->patient->phone) {
                    $recipients[$appointment->patient->phone] = $appointment->patient->full_name ?? 'Patient';
                }
                break;

            case NotificationSchedules::METHOD_CONSULTANT:
                if ($appointment->consultant && $appointment->consultant->phone) {
                    $recipients[$appointment->consultant->phone] = $appointment->consultant->names ?? 'Doctor';
                }
                break;

            case NotificationSchedules::METHOD_BOTH:
            default:
                if ($appointment->patient && $appointment->patient->phone) {
                    $recipients[$appointment->patient->phone] = $appointment->patient->full_name ?? 'Patient';
                }
                if ($appointment->consultant && $appointment->consultant->phone) {
                    $recipients[$appointment->consultant->phone] = $appointment->consultant->names ?? 'Doctor';
                }
                break;
        }

        return $recipients;
    }

    /**
     * Get SMS message
     */
    private function getSmsMessage($appointment)
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
     * Send SMS using your preferred SMS service
     */
    private function sendSms($phone, $message)
    {
        // Example using a generic SMS service
        // Replace this with your actual SMS service integration (Twilio, AWS SNS, etc.)

        $smsConfig = Yii::$app->params['sms'] ?? [];
        $provider = $smsConfig['provider'] ?? 'twilio';

        switch ($provider) {
            case 'twilio':
                $this->sendTwilioSms($phone, $message);
                break;
            case 'aws':
                $this->sendAwsSns($phone, $message);
                break;
            default:
                throw new \Exception('SMS provider not configured');
        }
    }

    /**
     * Send SMS via Twilio
     */
    private function sendTwilioSms($phone, $message)
    {
        $config = Yii::$app->params['sms']['twilio'] ?? [];

        if (empty($config['sid']) || empty($config['token']) || empty($config['from'])) {
            throw new \Exception('Twilio SMS configuration missing');
        }

        // Example Twilio implementation
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.twilio.com/2010-04-01/Accounts/{$config['sid']}/Messages.json");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'From' => $config['from'],
            'To' => $phone,
            'Body' => $message
        ]));
        curl_setopt($ch, CURLOPT_USERPWD, $config['sid'] . ':' . $config['token']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new \Exception('Failed to send SMS: ' . $result);
        }
    }

    /**
     * Send SMS via AWS SNS
     */
    private function sendAwsSns($phone, $message)
    {
        // AWS SNS implementation would go here
        throw new \Exception('AWS SNS SMS not implemented yet');
    }
}