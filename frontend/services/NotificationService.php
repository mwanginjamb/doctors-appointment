<?php

namespace frontend\services;

use frontend\models\Appointments;
use frontend\models\NotificationSchedules;
use frontend\models\AppointmentNotifications;
use console\jobs\SendReminderEmailJob;
use console\jobs\SendReminderSmsJob;
use console\jobs\SendReminderPushJob;
use Yii;
use yii\helpers\ArrayHelper;

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
}