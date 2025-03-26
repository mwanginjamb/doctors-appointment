<?php
namespace console\controllers;

use frontend\models\Appointments;
use console\jobs\SendReminderEmailJob;
use Yii;
use yii\console\Controller;


class SendRemindersController extends Controller
{
    public function actionIndex()
    {
        $this->send5hrReminders();
        $this->send2hrReminders();
    }

    protected function send5hrReminders()
    {
        $appointments = Appointments::find()
            ->where(['reminder_5hrs_sent' => NULL])
            ->andWhere('CONCAT(date, " ",time) > NOW()')
            ->andWhere('DATE_SUB(CONCAT(date, " ",time), INTERVAL 5 HOUR) <= NOW()')
            ->all();

        foreach ($appointments as $appointment) {
            Yii::$app->queue->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => '5-hour'
            ]));
        }
        $count = count($appointments);
        $this->stdout("Successfully queued $count 5-hour reminders.\n", \yii\helpers\Console::FG_GREEN);
    }

    protected function send2hrReminders()
    {
        $appointments = Appointments::find()
            ->where(['reminder_2hrs_sent' => NULL])
            ->andWhere('CONCAT(date, " ",time) > NOW()')
            ->andWhere('DATE_SUB(CONCAT(date, " ",time), INTERVAL 2 HOUR) <= NOW()')
            ->all();

        foreach ($appointments as $appointment) {
            Yii::$app->queue->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => '2-hour'
            ]));
        }

        $count = count($appointments);
        $this->stdout("Successfully queued $count 2-hour reminders.\n", \yii\helpers\Console::FG_GREEN);
    }


}