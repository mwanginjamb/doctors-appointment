<?php

namespace console\jobs;

use frontend\models\Appointments;
use frontend\models\AppointmentNotifications;
use frontend\models\NotificationSchedules;
use yii\base\BaseObject;
use Yii;
use yii\helpers\VarDumper;

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

        Yii::info('Starting email reminder job execution - ' . VarDumper::dump($logContext), 'email.reminder.start');

        $appointment = Appointments::findOne($this->appointmentId);

        if (!$appointment) {
            return;
        }

        try {
            $recipients = $this->getRecipients($appointment);

            if (empty($recipients)) {
                throw new \Exception('No valid recipients found');
            }

            $subject = $this->getEmailSubject();
            $body = $this->getEmailBody($appointment);

            $mailer = Yii::$app->mailer->compose()
                ->setSubject($subject)
                ->setHtmlBody($body);

            // Send to recipients
            foreach ($recipients as $email => $name) {

                $emailStartTime = microtime(true);
                $emailLogContext = array_merge($logContext, [
                    'recipientEmail' => $email,
                    'recipientName' => $name
                ]);

                $personalizedBody = str_replace('{recipient_name}', $name, $body);
                $result = $mailer->setTo([$email => $name])
                    ->setHtmlBody($personalizedBody)
                    ->send();

                $emailDuration = microtime(true) - $emailStartTime;
                $emailLogContext['sendDuration'] = round($emailDuration, 4);
                $emailLogContext['sendResult'] = $result;

                if ($result) {
                    Yii::info('Email sent successfully - ' . VarDumper::dump($emailLogContext), 'email.reminder.sent');
                } else {
                    Yii::error('Email send returned false - ' . VarDumper::dump($emailLogContext), 'email.reminder.send_failed');
                }
            }

            // Update the old reminder fields for backward compatibility
            $this->updateLegacyReminderFields($appointment);

        } catch (\Exception $e) {
            // Mark notification as failed if we have notificationId
            if ($this->notificationId) {
                $notification = AppointmentNotifications::findOne($this->notificationId);
                if ($notification) {
                    $notification->markAsFailed($e->getMessage());
                }
            }

            Yii::error('Email reminder job failed: ' . $e->getMessage());
            throw $e;
        }
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
        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        if ($minutes >= 60) {
            $hours = $minutes / 60;
            $timeUnit = $hours == 1 ? '1 hour' : $hours . ' hours';
        } else {
            $timeUnit = $minutes . ' minutes';
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

        Yii::debug('Legacy reminder fields updated - ' . VarDumper::dump([
            'appointmentId' => $this->appointmentId,
            'minutes' => $minutes,
            'updateResult' => $result
        ]), 'email.reminder.legacy_update');
    }
}