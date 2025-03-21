<?php

namespace frontend\controllers;

use app\models\Appointments;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\base\Controller;


class ApiController extends Controller
{
    public function actionAppointments()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $appointments = Appointments::find()->where(['consultant_id' => Yii::$app->user->identity->id])->
            orWhere(['created_by' => Yii::$app->user->identity->id, 'patient_id' => Yii::$app->user->identity->id])->all();
        $events = [];

        foreach ($appointments as $app) {
            // datetime  string
            $start = $app->date . 'T' . $app->time;
            // Assume each appointment is 30min
            $endTimeStamp = strtotime($app->time) + env('DURATION', 30 * 60);
            $end = $app->date . 'T' . date('H:i:s', $endTimeStamp);
            $events[] = [
                'id' => $app->id,
                'title' => 'Patient Appointment',
                'start' => $start,
                'end' => $end
            ];
        }
        return $events;
    }

    public function actionVisit()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $request = Yii::$app->request;
        if ($request->isPost) {
            $data = json_decode($request->getRawBody(), true);
            $model = new Appointments();
            $startTime = strtotime($data['date'] . ' ' . $data['time']);
            $endTime = $startTime + env('DURATION', 30 * 60);

            //Define Doctors working hours
            $workingStart = strtotime($data['date'] . ' 09:00:00');
            $workingEnd = strtotime($data['date'] . ' 17:00:00');

            // Check if the appointment is within working hours.
            if ($startTime < $workingStart || $endTime > $workingEnd) {
                return ['status' => 'error', 'message' => "Appointment time is outside of doctor's working hours."];
            }

            // Check for conflicting appointments on the same day.
            $conflict = $model::find()
                ->select('patient_id')
                ->where(['date' => $data['date']])
                ->andWhere(['between', 'time', date('H:i:s', $startTime), date('H:i:s', $endTime)])
                ->andWhere(['consultant_id' => $data['consultant']])
                ->one();
            if ($conflict) {
                return ['status' => 'error', 'message' => "Doctor is not available at this time  - " . $conflict->patient_id];
            }

            // Populate the appointment model.
            $model->date = $data['date'];
            $model->time = $data['time'];
            $model->patient_id = $data['patient_name'];
            $model->consultant_id = $data['consultant'];
            $model->symptoms_brief = $data['brief'];

            if ($model->save()) {
                return ['status' => 'success'];
            } else {
                return ['status' => 'error', 'errors' => $model->errors];
            }
        }
        throw new BadRequestHttpException('Invalid Request.');
    }

    public function actionUpdateVisit()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $request = Yii::$app->request;
        if ($request->isPost) {
            $data = json_decode($request->getRawBody(), true);
            $model = Appointments::findOne($data['id']);
            if ($model) {
                $model->date = $data['date'];
                $model->time = $data['time'];


                $startTime = strtotime($data['date'] . ' ' . $data['time']);
                $endTime = $startTime + env('DURATION', 30 * 60);

                //Define Doctors working hours
                $workingStart = strtotime($data['date'] . ' 09:00:00');
                $workingEnd = strtotime($data['date'] . ' 17:00:00');


                // Check if the appointment is within working hours.
                if ($startTime < $workingStart || $endTime > $workingEnd) {
                    return ['status' => 'error', 'message' => "Appointment time is outside of doctor's working hours."];
                }

                // Check for conflicting appointments on the same day.
                $conflict = $model::find()
                    ->where(['date' => $data['date']])
                    ->andWhere(['between', 'time', date('H:i:s', $startTime), date('H:i:s', $endTime)])
                    ->andWhere(['consultant_id' => 1])
                    ->exists();
                if ($conflict) {
                    return ['status' => 'error', 'message' => "Doctor is not available at this time."];
                }



                if ($model->save()) {
                    return ['status' => 'success', 'message' => 'Appointment updated'];
                }
            }
        }
    }
}