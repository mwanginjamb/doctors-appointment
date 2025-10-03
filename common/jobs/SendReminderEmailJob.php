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

        // eager load the appointment with patient and consultant relations
        $appointment = Appointments::find()
            ->where(['id' => $this->appointmentId])
            ->with(['patient', 'consultant'])
            ->one();

        // check if appointment exists
        if (!$appointment) {
            Yii::info('No valid appointment found', 'notifications');
            return;
        }

        $this->consultant = $appointment ? $appointment->consultant : null;


        // Log consultant details for debugging
        if ($this->consultant) {
            Yii::info('Consultant loaded: ' . $this->consultant->names . ' (' . ($this->consultant->consultant_email ?? 'no email') . ')', 'notifications');
        } else {
            Yii::warning('No consultant found for appointment: ' . $this->appointmentId, 'notifications');
        }

        // Send to recipients
        $this->sendMail($appointment);

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
                        Yii::info('Added patient recipient: ' . $appointment->patient->email, 'notifications');
                    }
                    break;

                case NotificationSchedules::METHOD_CONSULTANT:
                    if ($this->consultant && $this->consultant->consultant_email) {
                        $recipients[$this->consultant->consultant_email] = $this->consultant->names ?? 'Doctor';
                        Yii::info('Added consultant recipient: ' . $this->consultant->consultant_email, 'notifications');
                    }
                    break;
                case NotificationSchedules::METHOD_BOTH:
                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                        // log this scenario and the patient email used
                        Yii::info('Added patient recipient: ' . $appointment->patient->email, 'notifications');
                    }
                    if ($this->consultant && $this->consultant->consultant_email) {
                        $recipients[$this->consultant->consultant_email] = $this->consultant->names ?? 'Doctor';
                        // log this scenario and the consultant email used
                        Yii::info('Added consultant recipient: ' . $this->consultant->consultant_email, 'notifications');
                    } else {
                        Yii::warning('Consultant or email not available for BOTH method', 'notifications');
                    }

                    break;
                default:
                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                    }
                    if ($this->consultant && $this->consultant->consultant_email) {
                        $recipients[$this->consultant->consultant_email] = $this->consultant->names ?? 'Doctor';
                    } else {
                        // log this scenario
                        Yii::info('Consultant email not found for appointment ID: ' . $appointment->id, 'notifications');
                    }
                    break;
            }
            if (empty($recipients)) {
                Yii::warning('No recipients found for appointment ' . $appointment->id . ' with method ' . $this->recipientType, 'notifications');
            } else {
                Yii::info('Total recipients: ' . count($recipients), 'notifications');
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
    public function sendMail(Appointments $appointment)
    {
        $recipients = $this->getRecipients($appointment);

        if (empty($recipients)) {
            Yii::info('No valid recipients found', 'notifications');
            throw new \Exception('No valid recipients found');
        }

        if ($this->consultant) {
            Yii::info('Using consultant: ' . $this->consultant->names, 'notifications');
        } else {
            Yii::info('No consultant details available', 'notifications');
        }

        $successCount = 0;
        $failCount = 0;

        try {
            foreach ($recipients as $email => $name) {
                try {
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

                    if ($mail) {
                        $successCount++;
                        Yii::info('Email sent successfully to ' . $email, 'notifications');
                    } else {
                        $failCount++;
                        Yii::error('Email failed to send to ' . $email, 'notifications');
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    Yii::error('Exception sending email to ' . $email . ': ' . $e->getMessage(), 'notifications');
                }
            }
        } catch (\Exception $e) {
            Yii::error('Email reminder job failed: ' . $e->getMessage(), 'notifications');
            throw $e;
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