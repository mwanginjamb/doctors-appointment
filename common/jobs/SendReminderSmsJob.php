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

            // Patients and consultants get differently worded messages
            foreach ($recipients as $phone => $role) {
                $this->sendSms($phone, $this->getSmsMessage($appointment, $role));
            }

            AppointmentNotifications::markAsSentById($this->notificationId);
        } catch (\Exception $e) {
            AppointmentNotifications::markAsFailedById($this->notificationId, $e->getMessage());
            Yii::error('SMS reminder job failed: ' . $e->getMessage(), 'notifications');
            throw $e;
        }
    }

    /**
     * Get SMS recipients based on recipient type, as phone => role (patient|consultant)
     */
    private function getRecipients($appointment)
    {
        $recipients = [];
        $toPatient = $this->recipientType !== NotificationSchedules::METHOD_CONSULTANT;
        $toConsultant = $this->recipientType !== NotificationSchedules::METHOD_PATIENT;

        if ($toPatient && $appointment->patient && $appointment->patient->phone_number) {
            $recipients[$appointment->patient->phone_number] = 'patient';
        }
        if ($toConsultant && $appointment->consultant && $appointment->consultant->consultant_phone_number) {
            $recipients[$appointment->consultant->consultant_phone_number] = 'consultant';
        }

        return $recipients;
    }

    /**
     * Get the SMS message worded for the recipient's role
     */
    private function getSmsMessage($appointment, $role)
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

        return "Hello {$patientName}, this is a reminder that your appointment with {$consultantName} is scheduled for {$appointmentDate} at {$appointmentTime} (in {$timeUnit}). Please arrive 15 minutes early."
            . self::manageNote($appointment);
    }

    /**
     * " To reschedule or cancel: <link>" for the patient, while they can still change it themselves.
     */
    private static function manageNote($appointment)
    {
        $url = \frontend\services\SelfServiceLink::urlIfChangeable($appointment);

        return $url ? " To reschedule or cancel: {$url}" : '';
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