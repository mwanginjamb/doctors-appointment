<?php

namespace frontend\services;

use frontend\models\Appointments;
use frontend\models\NotificationSchedules;
use frontend\models\AppointmentNotifications;
use common\jobs\SendReminderEmailJob;
use common\jobs\SendReminderSmsJob;
use common\jobs\SendReminderPushJob;
use common\jobs\SendReminderWhatsAppJob;
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

        // Log actual notifications being processed -use an array mapper
        if (count($pendingNotifications) > 0) {
            // Log pending notifications retrieved and due for dispatch
            Yii::info('Pending notifications to process: ' . count($pendingNotifications), 'notifications');
            Yii::info('Processing notifications: ' . json_encode(array_map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'appointment_id' => $notification->appointment_id,
                    'scheduled_time' => $notification->scheduled_time,
                    'status' => $notification->status,
                    'minutes_before' => $notification->minutes_before,
                    'notification_type' => $notification->notification_type,
                ];
            }, $pendingNotifications)), 'notifications');
        }

        foreach ($pendingNotifications as $notification) {
            // Skip if appointment is cancelled or past
            $appointment = $notification->appointment;
            $appointmentDateTime = new \DateTime($appointment->date . ' ' . $appointment->time);

            if ($appointmentDateTime <= new \DateTime()) {
                $notification->status = AppointmentNotifications::STATUS_CANCELLED;
                $notification->save(false);
                continue;
            }
            // Queue the appropriate job based on notification type
            try {
                self::queueNotificationJob($notification);
                $notification->markAsSent();
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
        $schedule = $notification->notificationSchedule;

        switch ($notification->notification_type) {
            case NotificationSchedules::TYPE_EMAIL:
                Yii::$app->queue->push(new SendReminderEmailJob([
                    'appointmentId' => $notification->appointment_id,
                    'reminderType' => $notification->minutes_before . '-minute',
                    'notificationId' => $notification->id,
                    'recipientType' => $schedule->notification_method
                ]));
                break;

            case NotificationSchedules::TYPE_SMS:
                Yii::$app->queue->push(new SendReminderSmsJob([
                    'appointmentId' => $notification->appointment_id,
                    'reminderType' => $notification->minutes_before . '-minute',
                    'notificationId' => $notification->id,
                    'recipientType' => $schedule->notification_method
                ]));
                break;

            case NotificationSchedules::TYPE_PUSH:
                Yii::$app->queue->push(new SendReminderPushJob([
                    'appointmentId' => $notification->appointment_id,
                    'reminderType' => $notification->minutes_before . '-minute',
                    'notificationId' => $notification->id,
                    'recipientType' => $schedule->notification_method
                ]));
                break;

            case NotificationSchedules::TYPE_META:
                Yii::$app->queue->push(new SendReminderWhatsAppJob([
                    'appointmentId' => $notification->appointment_id,
                    'reminderType' => $notification->minutes_before . '-minute',
                    'notificationId' => $notification->id,
                    'recipientType' => $schedule->notification_method
                ]));
                break;
        }
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
            Yii::$app->queue->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => 'immediate',
                'notificationId' => null,
                'recipientType' => NotificationSchedules::METHOD_BOTH
            ]));
        } else {
            Yii::error('No patient email found for immediate confirmation notification for appointment ' . $appointment->id, 'notifications');
        }
    }

    // reschedule notification
    public static function sendRescheduleNotification(Appointments $appointment)
    {
        // use the email job queue immediately without scheduling
        if ($appointment->patient && $appointment->patient->email) {
            Yii::$app->queue->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => 'reschedule',
                'notificationId' => null,
                'recipientType' => NotificationSchedules::METHOD_BOTH
            ]));
        } else {
            Yii::error('No patient email found for reschedule notification for appointment ' . $appointment->id, 'notifications');
        }
    }
}