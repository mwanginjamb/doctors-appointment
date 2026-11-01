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
        $appointment = Appointments::findOne($this->appointmentId);
        if (!$appointment) {
            Yii::error('Appointment not found: ' . $this->appointmentId, 'notifications');
            return;
        }

        $this->consultant = $appointment->consultant;
        if (!$this->consultant) {
            Yii::warning('No consultant profile for appointment ' . $appointment->id, 'notifications');
        }

        try {
            $this->sendMail($appointment);
            // Update the old reminder fields for backward compatibility
            $this->updateLegacyReminderFields($appointment);
            AppointmentNotifications::markAsSentById($this->notificationId);
        } catch (\Exception $e) {
            AppointmentNotifications::markAsFailedById($this->notificationId, $e->getMessage());
            Yii::error('Email reminder job failed: ' . $e->getMessage(), 'notifications');
            throw $e;
        }
    }

    /**
     * Get email recipients based on recipient type
     */
    private function getRecipients($appointment)
    {
        $recipients = [];

        Yii::info('Determining recipients for type: ' . $this->recipientType, 'notifications');

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
                    } else {
                        Yii::warning('Consultant or email not available', 'notifications');
                    }
                    break;

                case NotificationSchedules::METHOD_BOTH:
                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                        Yii::info('Added patient recipient: ' . $appointment->patient->email, 'notifications');
                    }
                    if ($this->consultant && $this->consultant->consultant_email) {
                        $recipients[$this->consultant->consultant_email] = $this->consultant->names ?? 'Doctor';
                        Yii::info('Added consultant recipient: ' . $this->consultant->consultant_email, 'notifications');
                    } else {
                        Yii::warning('Consultant or email not available for BOTH method', 'notifications');
                    }
                    break;

                default:
                    // Default to both if method is not recognized
                    if ($appointment->patient && $appointment->patient->email) {
                        $recipients[$appointment->patient->email] = $appointment->patient->full_name ?? 'Patient';
                    }
                    if ($this->consultant && $this->consultant->consultant_email) {
                        $recipients[$this->consultant->consultant_email] = $this->consultant->names ?? 'Doctor';
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
            Yii::error('Error determining recipients: ' . $e->getMessage(), 'notifications');
            return [];
        }
    }

    /**
     * Get email subject
     */
    private function getEmailSubject()
    {
        // Check if reminderType has string '-minute' first
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
        if (strpos($this->reminderType, '-minute') === false) {
            return;
        }

        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        // Update the legacy reminder fields if they match common times
        if ($minutes == 300) { // 5 hours
            $appointment->reminder_5hrs_sent = 1;
        } elseif ($minutes == 120) { // 2 hours
            $appointment->reminder_2hrs_sent = 1;
        } else {
            // No legacy field to update
            return;
        }

        // Use updateAll to bypass afterSave() hook and prevent infinite loops
        Appointments::updateAll(
            [
                'reminder_5hrs_sent' => $appointment->reminder_5hrs_sent,
                'reminder_2hrs_sent' => $appointment->reminder_2hrs_sent,
            ],
            ['id' => $appointment->id]
        );
    }

    /**
     * Send email to all recipients
     */
    public function sendMail(Appointments $appointment)
    {
        $recipients = $this->getRecipients($appointment);

        if (empty($recipients)) {
            Yii::warning('No valid recipients found for appointment ' . $appointment->id, 'notifications');
            throw new \Exception('No valid recipients found');
        }

        $successCount = 0;
        $failCount = 0;
        $bcc = Yii::$app->params['notificationBcc'] ?? null;

        foreach ($recipients as $email => $name) {
            try {
                $message = Yii::$app->mailer->compose('appointmentReminder-html', [
                    'appointment' => $appointment,
                    'timeUnit' => $this->getTimeUnit(),
                    'recipientName' => $name,
                    'consultant' => $this->consultant,
                ])
                    ->setTo([$email => $name])
                    ->setFrom([Yii::$app->params['supportEmail'] => Yii::$app->name . ' Messenger'])
                    ->setSubject($this->getEmailSubject());
                if ($bcc) {
                    $message->setBcc($bcc);
                }

                if ($message->send()) {
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

        Yii::info("Email batch complete: {$successCount} sent, {$failCount} failed", 'notifications');

        if ($successCount === 0) {
            throw new \Exception("All {$failCount} reminder emails failed to send");
        }
    }

    /**
     * Get the time unit string for display
     */
    private function getTimeUnit()
    {
        if (strpos($this->reminderType, '-minute') === false) {
            return $this->reminderType;
        }

        $minutes = (int) str_replace('-minute', '', $this->reminderType);

        if ($minutes >= 60) {
            $hours = $minutes / 60;
            return $hours == 1 ? '1 hour' : $hours . ' hours';
        } else {
            return $minutes . ' minutes';
        }
    }
}