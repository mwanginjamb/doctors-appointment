<?php
namespace console\controllers;

use frontend\models\Appointments;
use console\jobs\SendReminderEmailJob;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;


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
            ->andWhere(['=', 'date', date('Y-m-d')])
            ->andWhere([
                'BETWEEN',
                new \yii\db\Expression("STR_TO_DATE(CONCAT(date, ' ', time), '%Y-%m-%d %H:%i:%s')"),
                new \yii\db\Expression("DATE_ADD(NOW(), INTERVAL 299 MINUTE)"), // 4h 59m
                new \yii\db\Expression("DATE_ADD(NOW(), INTERVAL 301 MINUTE)")  // 5h 1m
            ])
            ->all();

        foreach ($appointments as $appointment) {
            Yii::$app->queue->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => '5-hour'
            ]));
        }
        $count = count($appointments);
        Yii::info("Successfully queued {$count} 5-hour reminders.", 'jobs');
        $this->stdout("Successfully queued {$count} 5-hour reminders.\n", \yii\helpers\Console::FG_GREEN);
    }

    protected function send2hrReminders()
    {
        $appointments = Appointments::find()
            ->where(['reminder_2hrs_sent' => NULL])
            ->andWhere(['=', 'date', date('Y-m-d')])
            ->andWhere([
                'BETWEEN',
                new \yii\db\Expression("STR_TO_DATE(CONCAT(date, ' ', time), '%Y-%m-%d %H:%i:%s')"),
                new \yii\db\Expression("DATE_ADD(NOW(), INTERVAL 119 MINUTE)"),
                new \yii\db\Expression("DATE_ADD(NOW(), INTERVAL 121 MINUTE)")
            ])
            ->all();

        foreach ($appointments as $appointment) {
            Yii::$app->queue->push(new SendReminderEmailJob([
                'appointmentId' => $appointment->id,
                'reminderType' => '2-hour'
            ]));
        }

        $count = count($appointments);
        Yii::info("Successfully queued {$count} 2-hour reminders.", 'jobs');
        $this->stdout("Successfully queued {$count} 2-hour reminders.\n", \yii\helpers\Console::FG_GREEN);
    }

    public function actionFindDueInHours($hours = 2, $toleranceMinutes = 1)
    {
        $startOffset = ($hours * 60) - $toleranceMinutes;
        $endOffset = ($hours * 60) + $toleranceMinutes;
        $appointments = Appointments::find()
            //->where(['reminder_2hrs_sent' => NULL])
            ->andWhere(['=', 'date', date('Y-m-d')])
            ->andWhere([
                'BETWEEN',
                new \yii\db\Expression("STR_TO_DATE(CONCAT(date, ' ', time), '%Y-%m-%d %H:%i:%s')"),
                new \yii\db\Expression("DATE_ADD(NOW(), INTERVAL {$startOffset} MINUTE)"),
                new \yii\db\Expression("DATE_ADD(NOW(), INTERVAL {$endOffset} MINUTE)")
            ])
            ->all();

        //return $appointments;
        if (empty($appointments)) {
            $this->stdout("No appointments found within the specified range.\n", \yii\helpers\Console::FG_YELLOW);
        } else {
            foreach ($appointments as $appointment) {
                // Customize this output as needed
                $this->stdout("Appointment ID: {$appointment->id}, Date: {$appointment->date}, Time: {$appointment->time}, Brief: {$appointment->symptoms_brief} \n", \yii\helpers\Console::FG_GREEN);
            }
        }

        return ExitCode::OK; // Proper way to exit a Yii console action
    }


}