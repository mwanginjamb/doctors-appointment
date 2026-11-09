<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;
use frontend\services\NotificationService;
use frontend\models\AppointmentNotifications;
use frontend\models\Appointments;
use Yii;

/**
 * Notification controller for managing appointment notifications
 */
class NotificationController extends Controller
{
    /**
     * Process pending notifications
     * Usage: php yii notification/process
     */
    public function actionProcess()
    {
        $this->stdout("Starting notification processing...\n");

        try {
            NotificationService::processPendingNotifications();
            $this->stdout("Notification processing completed successfully.\n");
            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error processing notifications: " . $e->getMessage() . "\n");
            Yii::error('Notification processing failed: ' . $e->getMessage());
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Schedule notifications for existing appointments that don't have them
     * Usage: php yii notification/schedule-missing
     */
    public function actionScheduleMissing()
    {
        $this->stdout("Scheduling missing notifications...\n");

        $count = 0;

        // Get appointments that are in the future and don't have notifications
        $appointments = Appointments::find()
            ->where(['>', 'CONCAT(date, " ", time)', date('Y-m-d H:i:s')])
            ->all();

        foreach ($appointments as $appointment) {
            $hasNotifications = AppointmentNotifications::find()
                ->where(['appointment_id' => $appointment->id])
                ->exists();

            if (!$hasNotifications) {
                NotificationService::scheduleNotificationsForAppointment($appointment);
                $count++;
            }
        }

        $this->stdout("Scheduled notifications for {$count} appointments.\n");
        return ExitCode::OK;
    }

    /**
     * Clean up old notification records
     * Usage: php yii notification/cleanup [days]
     */
    public function actionCleanup($days = 30)
    {
        $this->stdout("Cleaning up old notification records...\n");

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = AppointmentNotifications::deleteAll([
            'and',
            ['in', 'status', [AppointmentNotifications::STATUS_SENT, AppointmentNotifications::STATUS_FAILED]],
            ['<', 'created_at', strtotime($cutoffDate)]
        ]);

        $this->stdout("Deleted {$deleted} old notification records.\n");
        return ExitCode::OK;
    }

    /**
     * Show notification statistics
     * Usage: php yii notification/stats
     */
    public function actionStats()
    {
        $this->stdout("Notification Statistics:\n");
        $this->stdout("======================\n");

        $now = date('Y-m-d H:i:s');

        $pending = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_PENDING])
            ->count();

        // Pending rows whose time has passed should be picked up by the next scheduler run
        $due = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_PENDING])
            ->andWhere(['<=', 'scheduled_time', $now])
            ->count();

        $queued = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_QUEUED])
            ->count();

        // Window on when delivery happened, not when the reminder row was created
        $sent = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_SENT])
            ->andWhere(['>', 'sent_at', date('Y-m-d H:i:s', strtotime('-7 days'))])
            ->count();

        // No failed_at column; updated_at is stamped when the row is marked failed/cancelled
        $failed = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_FAILED])
            ->andWhere(['>', 'updated_at', strtotime('-7 days')])
            ->count();

        $cancelled = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_CANCELLED])
            ->andWhere(['>', 'updated_at', strtotime('-7 days')])
            ->count();

        $next = AppointmentNotifications::find()
            ->where(['status' => AppointmentNotifications::STATUS_PENDING])
            ->andWhere(['>', 'scheduled_time', $now])
            ->orderBy(['scheduled_time' => SORT_ASC])
            ->one();

        $this->stdout("Pending notifications: {$pending} ({$due} due now)\n");
        $this->stdout("Queued (awaiting worker): {$queued}\n");
        $this->stdout("Sent (last 7 days): {$sent}\n");
        $this->stdout("Failed (last 7 days): {$failed}\n");
        $this->stdout("Cancelled (last 7 days): {$cancelled}\n");
        $this->stdout("Next due: " . ($next
            ? "{$next->scheduled_time} (#{$next->id}, {$next->notification_type}, appointment #{$next->appointment_id})"
            : 'none') . "\n");
        $this->stdout("Clock: {$now} " . date_default_timezone_get() . "\n");

        return ExitCode::OK;
    }

    /**
     * Test notification system with a specific appointment
     * Usage: php yii notification/test [appointment_id]
     */
    public function actionTest($appointmentId)
    {
        $appointment = Appointments::findOne($appointmentId);

        if (!$appointment) {
            $this->stderr("Appointment with ID {$appointmentId} not found.\n");
            return ExitCode::DATAERR;
        }

        $this->stdout("Testing notifications for appointment #{$appointmentId}...\n");

        try {
            NotificationService::scheduleNotificationsForAppointment($appointment);
            $this->stdout("Notifications scheduled successfully.\n");

            $notifications = AppointmentNotifications::find()
                ->where(['appointment_id' => $appointmentId])
                ->all();

            foreach ($notifications as $notification) {
                $this->stdout("- {$notification->notification_type} notification scheduled for " .
                    $notification->minutes_before . " minutes before at " .
                    $notification->scheduled_time . "\n");
            }

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}