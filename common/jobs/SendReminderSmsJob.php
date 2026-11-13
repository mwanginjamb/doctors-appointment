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
            // F8: consultants working at several facilities need to know where
            $where = $appointment->facility ? " at {$appointment->facility->name}" : '';
            return "Hello {$consultantName}, this is a reminder that you have an appointment with {$patientName}{$where} scheduled for {$appointmentDate} at {$appointmentTime} (in {$timeUnit}).";
        }

        return "Hello {$patientName}, this is a reminder that your appointment with {$consultantName} is scheduled for {$appointmentDate} at {$appointmentTime} (in {$timeUnit}), at {$appointment->getPlaceText()}. Please arrive 15 minutes early."
            . self::manageNote($appointment);
    }

    /**
     * " To confirm you are coming, choose another time or cancel: <link>" for the patient, for what
     * they can still do themselves (confirm until the start; change outside the cut-off).
     */
    private static function manageNote($appointment)
    {
        return \frontend\services\SelfServiceLink::messageNote($appointment);
    }

    /**
     * Send the SMS through the configured provider (see SmsClient)
     */
    private function sendSms($phone, $message)
    {
        \common\components\SmsClient::send($phone, $message);
    }
}
