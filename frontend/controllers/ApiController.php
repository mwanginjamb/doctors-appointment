<?php

namespace frontend\controllers;

use frontend\models\Appointments;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\base\Controller;


class ApiController extends Controller
{
    public function actionAppointments()
    {
        $consultant_id = null;
        $role = Yii::$app->user->identity->role;
        $userEvents = [];
        $othersEvents = [];
        $events = [];


        Yii::$app->response->format = Response::FORMAT_JSON;
        if ($role == 'client') {
            $appointments = Appointments::find()->where(['patient_id' => Yii::$app->user->identity->id])->all();
            // if $appointments exist for the patient, get the consultant_id from the first item
            if ($appointments) {
                $consultant_id = $appointments[0]->consultant_id;
            }
            //other users appointment for the same consultant but nit from the logged in patient_id
            $other_appointments = Appointments::find()->
                where(['not', ['patient_id' => Yii::$app->user->identity->id]])->
                andWhere(['consultant_id' => $consultant_id])->all();
        } else if ($role == 'consultant') {
            $appointments = Appointments::find()->where(['consultant_id' => Yii::$app->user->identity->id])->all();
        }



        // Process a users events with relevant metadata
        foreach ($appointments as $app) {
            // datetime  string
            $start = $app->date . 'T' . $app->time;
            // Assume each appointment is 30min
            $endTimeStamp = strtotime($app->time) + env('DURATION', 30 * 60);
            $end = $app->date . 'T' . date('H:i:s', $endTimeStamp);
            $userEvents[] = [
                'id' => $app->id,
                'title' => $app->patient->full_name ?? 'Patient' . ' Appointment with ' . $app->consultant->names ?? 'Dr.',
                'description' => ' Subject: ' . mb_substr($app->symptoms_brief, 0, 200),
                'start' => $start,
                'end' => $end
            ];
        }

        // Process others events with only necessary metadata
        foreach ($other_appointments as $app) {
            // datetime  string
            $start = $app->date . 'T' . $app->time;
            // Assume each appointment is 30min
            $endTimeStamp = strtotime($app->time) + env('DURATION', 30 * 60);
            $end = $app->date . 'T' . date('H:i:s', $endTimeStamp);
            $othersEvents[] = [
                'id' => $app->id,
                'title' => 'Patient Appointment with ' . $app->consultant->names ?? 'Dr.',
                'description' => ' Subject: ' . mb_substr($app->symptoms_brief, 0, 5) . ' ...',
                'start' => $start,
                'end' => $end
            ];
        }

        if ($role == 'client') {
            $events = array_merge($userEvents, $othersEvents);
        } else {
            $events = $userEvents;
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
                ->select('patient_id', 'consultant_id')
                ->where(['date' => $data['date']])
                ->andWhere(['between', 'time', date('H:i:s', $startTime), date('H:i:s', $endTime)])
                ->andWhere(['consultant_id' => $data['consultant']])
                ->exists();
            if ($conflict) {
                return ['status' => 'error', 'message' => "Doctor " . $data['consultant'] . " is not available at this time  - " . $conflict];
            }

            // Populate the appointment model.
            $model->date = $data['date'];
            $model->time = $data['time'];
            $model->patient_id = $data['patient_name'];
            $model->consultant_id = $data['consultant'];
            $model->symptoms_brief = $data['brief'];

            if ($model->save()) {
                return ['status' => 'success', 'message' => 'Appointment #' . $model->id . ' booked successfully!'];
            } else {
                return ['status' => 'error', 'message' => 'Saving Error'];
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
                    // ->andWhere(['<>', 'id', $data['id']]) // Exclude the current appointment
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