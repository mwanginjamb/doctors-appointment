<?php
namespace common\jobs;

use frontend\models\Appointments;
use frontend\models\AppointmentNotifications;
use frontend\models\NotificationSchedules;
use yii\base\BaseObject;
use Yii;


class SendReminderEmailJob extends BaseObject implements \yii\queue\JobInterface
{
    public $appointmentId;
    public $reminderType;
    public $notificationId;
    public $recipientType;

    private $consultant;

    public function execute($queue)
    {

        $logContext = [
            'appointmentId' => $this->appointmentId,
            'reminderType' => $this->reminderType,
            'notificationId' => $this->notificationId,
            'recipientType' => $this->recipientType,
            'jobId' => uniqid('job_', true)
        ];

        $appointment = Appointments::findOne(['id' => $this->appointmentId]);
        $consultant = $appointment ? $appointment->consultant : null;
        // $appointment = Appointments::find()->where(['appointments.id' => $this->appointmentId])->with(['patient', 'consultant'])->one();

        // log the appointment details
        Yii::info('Subject  Appointment: ' . print_r($appointment, true), 'notifications');
        if (!$appointment) {
            Yii::info('No valid appointment found', 'notifications');
            return;
        }

        // Send to recipients
        $this->sendMail($appointment, $consultant);

        // Update the old reminder fields for backward compatibility
        $this->updateLegacyReminderFields($appointment);
    }

    /**
     * Get email recipients based on recipient type
     */
    private function getRecipients($appointment)
    {
        $recipients = [];

        // log receipient type
        Yii::info('Recipient type: ' . $this->recipientType, 'notifications');
        try {
            switch ($this->recipientType) {
                case NotificationSchedules::METHOD_PATIENT:
                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                    }
                    break;

                case NotificationSchedules::METHOD_CONSULTANT:
                    if ($appointment->consultant && $appointment->consultant->consultant_email) {
                        $recipients[$appointment->consultant->consultant_email] = $appointment->consultant->names ?? 'Doctor';
                    }
                    break;

                case NotificationSchedules::METHOD_BOTH:

                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                        // log this scenario and the patient email used
                        Yii::info('Patient email used: ' . $appointment->patient->email, 'notifications');
                    }
                    if ($appointment->consultant && $appointment->consultant->consultant_email) {
                        $recipients[$appointment->consultant->consultant_email] = $appointment->consultant->names ?? 'Doctor';
                        // log this scenario and the consultant email used
                        Yii::info('Consultant email used: ' . $appointment->consultant->consultant_email, 'notifications');
                    }
                    // log recipients to show receipients used
                    Yii::info('Recipients: ' . print_r($recipients, true), 'notifications');
                    break;
                default:
                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                    }
                    if ($appointment->consultant && $appointment->consultant->consultant_email) {
                        $recipients[$appointment->consultant->consultant_email] = $appointment->consultant->names ?? 'Doctor';
                    } else {
                        // log this scenario
                        Yii::info('Consultant email not found for appointment ID: ' . $appointment->id, 'notifications');
                    }
                    break;
            }
            return $recipients;
        } catch (\Exception $e) {
            Yii::error('Error determining recipient(s): ' . $e->getMessage(), 'notifications');
        }

    }

    /**
     * Get email subject
     */
    private function getEmailSubject()
    {
        // check if reminderType has string '-minute' first
        if (strpos($this->reminderType, '-minute') !== false) {
            $minutes = (int) str_replace('-minute', '', $this->reminderType);
            if ($minutes >= 60) {
                $hours = $minutes / 60;
                $timeUnit = $hours == 1 ? '1 hour' : $hours . ' hours';
            } else {
                $timeUnit = $minutes . ' minutes';
            }
        } else {
            $timeUnit = $this->reminderType;
        }


        return "Appointment Reminder - {$timeUnit} notice";
    }



    /**
     * Update legacy reminder fields for backward compatibility
     */
    private function updateLegacyReminderFields($appointment)
    {
        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        // Update the legacy reminder fields if they match common times
        if ($minutes == 300) { // 5 hours
            $appointment->reminder_5hrs_sent = 1;
        } elseif ($minutes == 120) { // 2 hours
            $appointment->reminder_2hrs_sent = 1;
        }

        $result = $appointment->save(false);


    }

    // Add an organized email sending function that uses a template
    public function sendMail(Appointments $appointment, $consultant = null)
    {
        $recipients = $this->getRecipients($appointment);

        if (empty($recipients)) {
            Yii::info('No valid recipients found', 'notifications');
            throw new \Exception('No valid recipients found');
        }

        if ($consultant) {
            Yii::info('Consultant details: ' . print_r($consultant, true), 'notifications');
        } else {
            Yii::info('No consultant details available', 'notifications');
        }

        try {
            foreach ($recipients as $email => $name) {
                $mail = Yii::$app->mailer->compose('appointmentReminder-html', [
                    'appointment' => $appointment,
                    'timeUnit' => $this->getTimeUnit(),
                    'recipientName' => $name,
                ])
                    ->setTo([$email => $name])
                    ->setFrom([Yii::$app->params['supportEmail'] => Yii::$app->name . ' robot'])
                    ->setBcc('fnjambi@outlook.com')
                    ->setSubject($this->getEmailSubject())
                    ->send();

                Yii::info('Email sent to ' . $email . ' - ' . ($mail ? 'Success' : 'Failed'), 'notifications');
            }
        } catch (\Exception $e) {
            Yii::error('Email reminder job failed: ' . $e->getMessage());
        }
    }

    // Define a method to get the time unit string
    private function getTimeUnit()
    {
        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        if ($minutes >= 60) {
            $hours = $minutes / 60;
            return $hours == 1 ? '1 hour' : $hours . ' hours';
        } else {
            return $minutes . ' minutes';
        }
    }
}