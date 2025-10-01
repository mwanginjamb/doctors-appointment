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

    public function execute($queue)
    {

        $logContext = [
            'appointmentId' => $this->appointmentId,
            'reminderType' => $this->reminderType,
            'notificationId' => $this->notificationId,
            'recipientType' => $this->recipientType,
            'jobId' => uniqid('job_', true)
        ];


        $appointment = Appointments::findOne($this->appointmentId);

        if (!$appointment) {
            Yii::info('No valid appointment found', 'notifications');
            return;
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
            default:
                if ($appointment->patient && $appointment->patient->email) {
                    $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                }
                if ($appointment->consultant && $appointment->consultant->consultant_email) {
                    $recipients[$appointment->consultant->email] = $appointment->consultant->names ?? 'Doctor';
                }
                break;
        }

        return $recipients;
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
     * Get email body
     */
    private function getEmailBody($appointment)
    {
        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        if ($minutes >= 60) {
            $hours = $minutes / 60;
            $timeUnit = $hours == 1 ? '1 hour' : $hours . ' hours';
        } else {
            $timeUnit = $minutes . ' minutes';
        }

        $appointmentDate = date('l, F j, Y', strtotime($appointment->date));
        $appointmentTime = date('g:i A', strtotime($appointment->time));

        $patientName = $appointment->patient->full_name ?? 'Patient';
        $consultantName = $appointment->consultant->names ?? 'Doctor';
        $location = $appointment->consultant->physical_address ?? 'Location not specified';

        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2c5aa0;'>Appointment Reminder</h2>
                
                <p>Dear {recipient_name},</p>
                
                <p>This is a friendly reminder that you have an appointment coming up in <strong>{$timeUnit}</strong>.</p>
                
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #2c5aa0;'>Appointment Details</h3>
                    <p><strong>Date:</strong> {$appointmentDate}</p>
                    <p><strong>Time:</strong> {$appointmentTime}</p>
                    <p><strong>Patient:</strong> {$patientName}</p>
                    <p><strong>Doctor:</strong> {$consultantName}</p>
                    " . ($appointment->location ? "<p><strong>Location:</strong> {$appointment->location}</p>" : "") . "
                    " . ($appointment->symptoms_brief ? "<p><strong>Purpose:</strong> " . htmlspecialchars(substr($appointment->symptoms_brief, 0, 200)) . "</p>" : "") . "
                </div>
                
                <p>Please arrive 15 minutes early for check-in. If you need to reschedule or cancel, please contact us as soon as possible.</p>
                
                <p>Thank you!</p>
                
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666;'>
                    <p>This is an automated reminder. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";
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