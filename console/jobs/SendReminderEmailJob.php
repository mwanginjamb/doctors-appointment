<?php
// console/jobs/SendReminderEmailJob.php
namespace console\jobs;

use frontend\models\Appointments;
use yii\base\BaseObject;
use Yii;

class SendReminderEmailJob extends BaseObject implements \yii\queue\JobInterface
{
    public $appointmentId;
    public $reminderType;

    public function execute($queue)
    {
        $appointment = Appointments::findOne($this->appointmentId);

        if ($appointment) {
            Yii::$app->mailer->compose()
                ->setTo([$appointment->patient->email, $appointment->consultant->email]) // Adjust according to your structure
                ->setSubject("Appointment {$this->reminderType} Reminder")
                ->setTextBody("Your appointment is scheduled at {$appointment->appointment_date} {$appointment->appointment_time}")
                ->send();

            // Mark the reminder as sent
            if ($this->reminderType === '5-hour') {
                $appointment->reminder_5hrs_sent = 1;
            } elseif ($this->reminderType === '2-hour') {
                $appointment->reminder_2hrs_sent = 1;
            }
            $appointment->save(false);
        }
    }
}