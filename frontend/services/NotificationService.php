<?php

namespace frontend\services;

use frontend\models\Appointments;
use frontend\models\NotificationSchedules;
use frontend\models\AppointmentNotifications;
use common\jobs\SendReminderEmailJob;
use common\jobs\SendReminderSmsJob;
use common\jobs\SendReminderPushJob;
use common\jobs\SendReminderWhatsappJob;
use Yii;



/**
 * Service for managing appointment notifications
 */
class NotificationService
{
    /**
     * Schedule notifications for a new appointment
     */
    public static function scheduleNotificationsForAppointment(Appointments $appointment)
    {
        // Ensure default schedules exist for both users first
        if ($appointment->patient_id) {
            self::ensureDefaultSchedulesExist($appointment->patient_id);
        }

        if ($appointment->consultant_id) {
            self::ensureDefaultSchedulesExist($appointment->consultant_id);
        }

        // Get notification schedules for both patient and consultant
        $patientSchedules = NotificationSchedules::getActiveSchedulesForUser($appointment->patient_id);
        $consultantSchedules = NotificationSchedules::getActiveSchedulesForUser($appointment->consultant_id);

        // Combine schedules based on notification method
        $allSchedules = [];

        foreach ($patientSchedules as $schedule) {
            if (in_array($schedule->notification_method, [NotificationSchedules::METHOD_PATIENT, NotificationSchedules::METHOD_BOTH])) {
                $allSchedules[] = $schedule;
            }
        }

        foreach ($consultantSchedules as $schedule) {
            if (in_array($schedule->notification_method, [NotificationSchedules::METHOD_CONSULTANT, NotificationSchedules::METHOD_BOTH])) {
                $allSchedules[] = $schedule;
            }
        }

        // Create notification records
        foreach ($allSchedules as $schedule) {
            self::createNotificationRecord($appointment, $schedule);
        }
    }

    /**
     * Ensure user has default notification schedules
     */
    public static function ensureDefaultSchedulesExist($userId)
    {
        if (!$userId) {
            return false;
        }

        // Check if user already has notification schedules
        $hasSchedules = NotificationSchedules::find()
            ->where(['user_id' => $userId])
            ->exists();

        if (!$hasSchedules) {
            return self::createDefaultSchedulesForUser($userId);
        }

        return true;
    }

    /**
     * Create default notification schedules for a user
     */
    public static function createDefaultSchedulesForUser($userId)
    {
        if (!(Yii::$app->params['autoCreateDefaultSchedules'] ?? true)) {
            return false;
        }

        $defaultSchedules = Yii::$app->params['defaultNotificationSchedules'] ?? [
            [
                'notification_type' => NotificationSchedules::TYPE_EMAIL,
                'minutes_before' => 300, // 5 hours
                'is_active' => 1,
                'notification_method' => NotificationSchedules::METHOD_BOTH,
            ]
        ];

        $created = 0;
        foreach ($defaultSchedules as $scheduleData) {
            try {
                $schedule = new NotificationSchedules();
                $schedule->user_id = $userId;
                $schedule->notification_type = $scheduleData['notification_type'];
                $schedule->minutes_before = $scheduleData['minutes_before'];
                $schedule->is_active = $scheduleData['is_active'];
                $schedule->notification_method = $scheduleData['notification_method'];

                if ($schedule->save()) {
                    $created++;
                    Yii::info("Created default {$schedule->notification_type} notification schedule " .
                        "({$schedule->minutes_before} min) for user {$userId}");
                } else {
                    Yii::error("Failed to create default notification schedule for user {$userId}: " .
                        json_encode($schedule->errors));
                }
            } catch (\Exception $e) {
                Yii::error("Failed to create default notification schedule for user {$userId}: " .
                    $e->getMessage());
            }
        }

        return $created > 0;
    }

    /**
     * Create a notification record for an appointment
     */
    private static function createNotificationRecord(Appointments $appointment, NotificationSchedules $schedule)
    {
        // Calculate scheduled time
        $appointmentDateTime = new \DateTime($appointment->date . ' ' . $appointment->time);
        $scheduledDateTime = clone $appointmentDateTime;
        $scheduledDateTime->modify("-{$schedule->minutes_before} minutes");

        // Don't schedule notifications for past times
        if ($scheduledDateTime <= new \DateTime()) {
            return false;
        }

        // Check if notification already exists
        $exists = AppointmentNotifications::find()
            ->where([
                'appointment_id' => $appointment->id,
                'notification_schedule_id' => $schedule->id,
            ])
            ->exists();

        if ($exists) {
            return false;
        }

        $notification = new AppointmentNotifications();
        $notification->appointment_id = $appointment->id;
        $notification->notification_schedule_id = $schedule->id;
        $notification->notification_type = $schedule->notification_type;
        $notification->minutes_before = $schedule->minutes_before;
        $notification->scheduled_time = $scheduledDateTime->format('Y-m-d H:i:s');
        $notification->status = AppointmentNotifications::STATUS_PENDING;

        return $notification->save();
    }

    /**
     * Process pending notifications and queue jobs
     */
    public static function processPendingNotifications()
    {
        $pendingNotifications = AppointmentNotifications::getPendingNotifications();

        if (empty($pendingNotifications) || !is_array($pendingNotifications)) {
            return;
        }

        foreach ($pendingNotifications as $notification) {
            // Skip if appointment is gone, cancelled or past
            $appointment = $notification->appointment;
            if (
                !$appointment
                || $appointment->status === Appointments::STATUS_CANCELLED
                || new \DateTime($appointment->date . ' ' . $appointment->time) <= new \DateTime()
            ) {
                $notification->status = AppointmentNotifications::STATUS_CANCELLED;
                $notification->save(false);
                continue;
            }
            // F11: WhatsApp/SMS to a patient only with their consent (patients from before consent was
            // recorded keep them until they answer)
            if (in_array($notification->notification_type, [NotificationSchedules::TYPE_META, NotificationSchedules::TYPE_SMS], true)
                && self::getRecipientType($notification) === NotificationSchedules::METHOD_PATIENT
                && !\common\models\Consent::remindersAllowed($appointment->patient_id)) {
                $notification->status = AppointmentNotifications::STATUS_CANCELLED;
                $notification->error_message = 'Not sent: the patient has not agreed to WhatsApp/SMS reminders.';
                $notification->save(false);
                continue;
            }
            // Queue the appropriate job based on notification type; the job records delivery
            try {
                self::queueNotificationJob($notification);
                $notification->markAsQueued();
            } catch (\Exception $e) {
                $notification->markAsFailed($e->getMessage());
                Yii::error('Failed to queue notification: ' . $e->getMessage());
            }
        }
    }

    /**
     * Queue the appropriate notification job
     */
    private static function queueNotificationJob(AppointmentNotifications $notification)
    {
        $jobClasses = [
            NotificationSchedules::TYPE_EMAIL => SendReminderEmailJob::class,
            // SMS via Twilio / AWS SNS (see params['sms'])
            NotificationSchedules::TYPE_SMS => SendReminderSmsJob::class,
            // Push via Firebase Cloud Messaging HTTP v1
            NotificationSchedules::TYPE_PUSH => SendReminderPushJob::class,
            // WhatsApp via WAHA
            NotificationSchedules::TYPE_META => SendReminderWhatsappJob::class,
        ];

        if (!isset($jobClasses[$notification->notification_type])) {
            throw new \Exception('Unsupported notification type: ' . $notification->notification_type);
        }

        $jobClass = $jobClasses[$notification->notification_type];
        Yii::$app->queue->push(new $jobClass([
            'appointmentId' => $notification->appointment_id,
            'reminderType' => $notification->minutes_before . '-minute',
            'notificationId' => $notification->id,
            'recipientType' => self::getRecipientType($notification),
        ]));
    }

    /**
     * A schedule belongs to one user and reminds only that user. Each participant has their
     * own schedules, so sending a schedule to "both" would deliver every reminder twice.
     */
    private static function getRecipientType(AppointmentNotifications $notification)
    {
        $schedule = $notification->notificationSchedule;
        $appointment = $notification->appointment;

        if ($schedule && $appointment && (int) $schedule->user_id === (int) $appointment->consultant_id) {
            return NotificationSchedules::METHOD_CONSULTANT;
        }

        return NotificationSchedules::METHOD_PATIENT;
    }

    /**
     * Cancel all pending notifications for an appointment
     */
    public static function cancelNotificationsForAppointment($appointmentId)
    {
        AppointmentNotifications::updateAll(
            ['status' => AppointmentNotifications::STATUS_CANCELLED],
            [
                'appointment_id' => $appointmentId,
                'status' => AppointmentNotifications::STATUS_PENDING
            ]
        );
    }

    /**
     * Reschedule notifications for an updated appointment
     */
    public static function rescheduleNotificationsForAppointment(Appointments $appointment)
    {
        // Cancel existing pending notifications
        self::cancelNotificationsForAppointment($appointment->id);

        // Schedule new notifications
        self::scheduleNotificationsForAppointment($appointment);
    }

    // immediate confirmation notification
    public static function sendImmediateConfirmation(Appointments $appointment)
    {
        // use the email job queue immediately without scheduling
        if ($appointment->patient && $appointment->patient->email) {
            Yii::$app->queue->delay(10)->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => 'Booking Confirmation',
                'notificationId' => null,
                'recipientType' => NotificationSchedules::METHOD_PATIENT
            ]));


        } else {
            // Walk-in patients registered by front desk may have no email
            Yii::info('No patient email; skipped the booking confirmation email for appointment ' . $appointment->id, 'notifications');
        }
    }

    // send immediate confirmation notification
    public static function sendAppointmentConfirmation(Appointments $appointment)
    {
        // use the email job queue immediately without scheduling
        if ($appointment->patient && $appointment->patient->email) {
            Yii::$app->queue->delay(10)->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => 'Appointment Confirmation',
                'notificationId' => null,
                'recipientType' => NotificationSchedules::METHOD_BOTH
            ]));

        } else {
            Yii::info('No patient email; skipped the confirmation email for appointment ' . $appointment->id, 'notifications');
        }
    }
}