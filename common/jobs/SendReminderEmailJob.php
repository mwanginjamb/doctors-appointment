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
        // Set error handler to catch fatal errors
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                file_put_contents(
                    Yii::getAlias('@runtime/logs/job-debug.log'),
                    date('Y-m-d H:i:s') . " - FATAL ERROR: " . print_r($error, true) . "\n",
                    FILE_APPEND
                );
            }
        });

        // Force immediate logging to file
        file_put_contents(
            Yii::getAlias('@runtime/logs/job-debug.log'),
            date('Y-m-d H:i:s') . " - Job started for appointment {$this->appointmentId}\n",
            FILE_APPEND
        );

        $logContext = [
            'appointmentId' => $this->appointmentId,
            'reminderType' => $this->reminderType,
            'notificationId' => $this->notificationId,
            'recipientType' => $this->recipientType,
            'jobId' => uniqid('job_', true)
        ];

        try {
            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - About to check if Appointments class exists\n",
                FILE_APPEND
            );

            if (!class_exists('frontend\models\Appointments')) {
                file_put_contents(
                    Yii::getAlias('@runtime/logs/job-debug.log'),
                    date('Y-m-d H:i:s') . " - ERROR: Appointments class not found!\n",
                    FILE_APPEND
                );
                return;
            }

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Appointments class exists, fetching appointment {$this->appointmentId}\n",
                FILE_APPEND
            );

            // Try to fetch without eager loading first
            $appointment = Appointments::findOne($this->appointmentId);

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Basic appointment fetch: " . ($appointment ? 'SUCCESS' : 'NULL') . "\n",
                FILE_APPEND
            );

            if (!$appointment) {
                file_put_contents(
                    Yii::getAlias('@runtime/logs/job-debug.log'),
                    date('Y-m-d H:i:s') . " - ERROR: Appointment not found\n",
                    FILE_APPEND
                );
                Yii::error('Appointment not found: ' . $this->appointmentId, 'notifications');
                return;
            }

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Appointment data: " . json_encode([
                    'id' => $appointment->id,
                    'patient_id' => $appointment->patient_id,
                    'consultant_id' => $appointment->consultant_id,
                ]) . "\n",
                FILE_APPEND
            );

            // Now try to load patient
            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Loading patient relationship...\n",
                FILE_APPEND
            );

            $patient = $appointment->patient;
            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Patient loaded: " . ($patient ? get_class($patient) : 'NULL') . "\n",
                FILE_APPEND
            );

            // Now try to load consultant
            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Loading consultant relationship...\n",
                FILE_APPEND
            );

            $this->consultant = $appointment->consultant;

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Consultant loaded: " . ($this->consultant ? get_class($this->consultant) : 'NULL') . "\n",
                FILE_APPEND
            );

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Appointment loaded. consultant_id: " . ($appointment->consultant_id ?? 'NULL') . "\n",
                FILE_APPEND
            );

            // ✅ FIX: Set the class property so getRecipients() can access it
            $this->consultant = $appointment->consultant;

            // DIAGNOSTIC: Deep dive into consultant
            if ($this->consultant) {
                file_put_contents(
                    Yii::getAlias('@runtime/logs/job-debug.log'),
                    date('Y-m-d H:i:s') . " - Consultant loaded: " . json_encode($this->consultant->attributes) . "\n",
                    FILE_APPEND
                );
                Yii::info('Consultant object loaded - Class: ' . get_class($this->consultant), 'notifications');
                Yii::info('Consultant attributes: ' . json_encode($this->consultant->attributes), 'notifications');
            } else {
                file_put_contents(
                    Yii::getAlias('@runtime/logs/job-debug.log'),
                    date('Y-m-d H:i:s') . " - WARNING: Consultant is NULL\n",
                    FILE_APPEND
                );
                Yii::warning('Consultant relationship returned NULL for appointment: ' . $this->appointmentId . ' with consultant_id: ' . ($appointment->consultant_id ?? 'NULL'), 'notifications');
            }

            // DIAGNOSTIC: Log patient info too
            if ($appointment->patient) {
                file_put_contents(
                    Yii::getAlias('@runtime/logs/job-debug.log'),
                    date('Y-m-d H:i:s') . " - Patient email: " . ($appointment->patient->email ?? 'NULL') . "\n",
                    FILE_APPEND
                );
                Yii::info('Patient loaded - email: ' . ($appointment->patient->email ?? 'NULL'), 'notifications');
            }

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Calling sendMail()\n",
                FILE_APPEND
            );

            // Send to recipients
            $this->sendMail($appointment);

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Email sent, updating legacy fields\n",
                FILE_APPEND
            );

            // Update the old reminder fields for backward compatibility
            $this->updateLegacyReminderFields($appointment);

            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - Job completed successfully\n",
                FILE_APPEND
            );

        } catch (\Exception $e) {
            file_put_contents(
                Yii::getAlias('@runtime/logs/job-debug.log'),
                date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n",
                FILE_APPEND
            );
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

        file_put_contents(
            Yii::getAlias('@runtime/logs/job-debug.log'),
            date('Y-m-d H:i:s') . " - Updated legacy reminder fields via updateAll\n",
            FILE_APPEND
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

        if ($this->consultant) {
            Yii::info('Using consultant: ' . $this->consultant->names, 'notifications');
        } else {
            Yii::info('No consultant available for this notification', 'notifications');
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

            Yii::info("Email batch complete: {$successCount} sent, {$failCount} failed", 'notifications');

        } catch (\Exception $e) {
            Yii::error('Email reminder job failed: ' . $e->getMessage(), 'notifications');
            throw $e;
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