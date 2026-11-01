<?php

namespace frontend\tests\unit\services;

use common\jobs\SendReminderEmailJob;
use common\jobs\SendReminderWhatsappJob;
use frontend\models\AppointmentNotifications;
use frontend\models\Appointments;
use frontend\models\NotificationSchedules;
use frontend\services\NotificationService;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

class NotificationServiceTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /**
     * @var \frontend\tests\UnitTester
     */
    protected $tester;

    protected function _before()
    {
        $this->createParticipants();
    }

    public function testBookingCreatesDefaultSchedulesIncludingWhatsapp()
    {
        $appointment = $this->book('10:00');

        $types = NotificationSchedules::find()
            ->select('notification_type')
            ->where(['user_id' => $this->client->id])
            ->column();
        $this->assertContains(NotificationSchedules::TYPE_META, $types);
        $this->assertNotContains('whatapp', $types);

        $defaults = count(Yii::$app->params['defaultNotificationSchedules']);
        // one reminder per schedule, for the patient and for the consultant
        $this->assertSame(
            $defaults * 2,
            (int) AppointmentNotifications::find()->where(['appointment_id' => $appointment->id])->count()
        );
    }

    public function testEachReminderIsQueuedOnceForItsOwner()
    {
        $appointment = $this->book('10:00');
        AppointmentNotifications::updateAll(['scheduled_time' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        NotificationService::processPendingNotifications();

        $notifications = AppointmentNotifications::find()
            ->where(['appointment_id' => $appointment->id])
            ->with('notificationSchedule')
            ->indexBy('id')
            ->all();
        foreach ($notifications as $notification) {
            $this->assertSame(AppointmentNotifications::STATUS_QUEUED, $notification->status, 'Queued is not delivered');
        }

        $jobs = $this->queuedReminderJobs();
        $this->assertCount(count($notifications), $jobs);

        $seen = [];
        foreach ($jobs as $job) {
            $notification = $notifications[$job->notificationId];
            $expected = (int) $notification->notificationSchedule->user_id === $this->consultantUser->id
                ? NotificationSchedules::METHOD_CONSULTANT
                : NotificationSchedules::METHOD_PATIENT;
            $this->assertSame($expected, $job->recipientType);

            $key = get_class($job) . '|' . $job->reminderType . '|' . $job->recipientType;
            $this->assertArrayNotHasKey($key, $seen, "Duplicate reminder queued: {$key}");
            $seen[$key] = true;
        }

        $whatsappJobs = array_filter($jobs, function ($job) {
            return $job instanceof SendReminderWhatsappJob;
        });
        $this->assertNotEmpty($whatsappJobs);
    }

    public function testCancelledAppointmentRemindersAreNotQueued()
    {
        $appointment = $this->book('10:00');
        // bypass afterSave so the pending reminders are still there
        Appointments::updateAll(['status' => Appointments::STATUS_CANCELLED], ['id' => $appointment->id]);
        AppointmentNotifications::updateAll(['scheduled_time' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        NotificationService::processPendingNotifications();

        $this->assertEmpty($this->queuedReminderJobs());
        $this->assertSame(
            0,
            (int) AppointmentNotifications::find()
                ->where(['appointment_id' => $appointment->id])
                ->andWhere(['<>', 'status', AppointmentNotifications::STATUS_CANCELLED])
                ->count()
        );
    }

    public function testCancellingAppointmentCancelsPendingReminders()
    {
        $appointment = $this->book('10:00');
        $appointment->status = Appointments::STATUS_CANCELLED;
        $appointment->save(false);

        $this->assertSame(
            0,
            (int) AppointmentNotifications::find()
                ->where(['appointment_id' => $appointment->id, 'status' => AppointmentNotifications::STATUS_PENDING])
                ->count()
        );
    }

    public function testJobsRecordDeliveryOutcome()
    {
        $appointment = $this->book('10:00');
        $notification = AppointmentNotifications::find()->where(['appointment_id' => $appointment->id])->one();

        AppointmentNotifications::markAsFailedById($notification->id, 'SMTP down');
        $notification->refresh();
        $this->assertSame(AppointmentNotifications::STATUS_FAILED, $notification->status);
        $this->assertSame('SMTP down', $notification->error_message);

        AppointmentNotifications::markAsSentById($notification->id);
        $notification->refresh();
        $this->assertSame(AppointmentNotifications::STATUS_SENT, $notification->status);
        $this->assertNotEmpty($notification->sent_at);

        // ad-hoc jobs (booking confirmation etc.) have no notification record
        AppointmentNotifications::markAsSentById(null);
    }

    public function testBookingQueuesImmediateConfirmationEmail()
    {
        $this->book('10:00');

        $confirmations = array_filter($this->queuedJobs(), function ($job) {
            return $job instanceof SendReminderEmailJob && $job->reminderType === 'Booking Confirmation';
        });
        $this->assertCount(1, $confirmations);
    }

    /**
     * @return \yii\queue\JobInterface[]
     */
    private function queuedJobs()
    {
        $serializer = Yii::$app->queue->serializer;
        $payloads = (new Query())->select('job')->from(Yii::$app->queue->tableName)->column();

        return array_map(function ($payload) use ($serializer) {
            return $serializer->unserialize(is_resource($payload) ? stream_get_contents($payload) : $payload);
        }, $payloads);
    }

    /**
     * Jobs queued for scheduled reminders (ad-hoc confirmations carry no notification id).
     */
    private function queuedReminderJobs()
    {
        return array_values(array_filter($this->queuedJobs(), function ($job) {
            return $job->notificationId !== null;
        }));
    }
}
