<?php

namespace frontend\controllers;

use frontend\models\Appointments;
use frontend\models\Consultant;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;


class ApiController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'appointments' => ['GET'],
                    'visit' => ['POST'],
                    'update-visit' => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionAppointments()
    {
        $user = Yii::$app->user->identity;
        $appointments = [];
        $otherAppointments = [];

        if ($user->role === 'client') {
            $appointments = Appointments::find()->where(['patient_id' => $user->id])->with('consultant')->all();

            // The consultant being booked (stored by appointments/calendar), else the patient's latest consultant
            $consultantUserId = null;
            $consultant = Consultant::findOne(Yii::$app->session->get('booking_session_' . $user->id));
            if ($consultant) {
                $consultantUserId = $consultant->user_id;
            } elseif ($appointments) {
                $consultantUserId = $appointments[0]->consultant_id;
            }

            if ($consultantUserId) {
                // Other patients' bookings are shown only as busy slots
                $otherAppointments = Appointments::find()
                    ->where(['consultant_id' => $consultantUserId])
                    ->andWhere(['<>', 'patient_id', $user->id])
                    ->andWhere(['or', ['status' => null], ['<>', 'status', Appointments::STATUS_CANCELLED]])
                    ->with('consultant')
                    ->all();
            }
        } elseif ($user->role === 'consultant') {
            $appointments = Appointments::find()
                ->where(['or', ['consultant_id' => $user->id], ['created_by' => $user->id]])
                ->with(['consultant', 'patient'])
                ->all();
        }

        $events = [];
        foreach ($appointments as $app) {
            $patientName = $app->patient->full_name ?? 'Patient';
            $consultantName = $app->consultant->names ?? 'Dr.';
            $events[] = [
                'id' => $app->id,
                'title' => $patientName . ' Appointment with ' . $consultantName,
                'description' => ' Subject: ' . mb_substr((string) $app->symptoms_brief, 0, 200),
            ] + $this->eventTimes($app);
        }

        foreach ($otherAppointments as $app) {
            $events[] = [
                'title' => 'Booked',
                'description' => 'This slot is not available',
                'display' => 'background',
            ] + $this->eventTimes($app);
        }

        return $events;
    }

    public function actionVisit()
    {
        $data = $this->getJsonBody();
        $user = Yii::$app->user->identity;

        $model = new Appointments();
        $model->date = $data['date'] ?? null;
        $model->time = $data['time'] ?? null;
        $model->symptoms_brief = $data['brief'] ?? null;
        // Identities come from the session, never from the request body
        $model->patient_id = $user->id;
        $model->consultant_id = $user->role === 'consultant' ? $user->id : ($data['consultant'] ?? null);

        if ($model->saveBooking()) {
            return ['status' => 'success', 'message' => 'Appointment #' . $model->id . ' booked successfully!'];
        }

        return ['status' => 'error', 'message' => $this->firstError($model)];
    }

    public function actionUpdateVisit()
    {
        $data = $this->getJsonBody();

        $model = Appointments::findOne($data['id'] ?? null);
        if (!$model || !$model->isParticipant(Yii::$app->user->id)) {
            return ['status' => 'error', 'message' => 'Appointment not found.'];
        }

        $model->date = $data['date'] ?? null;
        $model->time = $data['time'] ?? null;

        if ($model->saveBooking()) {
            return ['status' => 'success', 'message' => 'Appointment updated'];
        }

        return ['status' => 'error', 'message' => $this->firstError($model)];
    }

    private function getJsonBody()
    {
        try {
            $data = Json::decode(Yii::$app->request->getRawBody());
        } catch (\yii\base\InvalidArgumentException $e) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new BadRequestHttpException('Invalid Request.');
        }

        return $data;
    }

    private function eventTimes(Appointments $app)
    {
        $start = strtotime($app->date . ' ' . $app->time);
        $end = $start + $app->getDurationMinutes() * 60;

        return [
            'start' => date('Y-m-d\TH:i:s', $start),
            'end' => date('Y-m-d\TH:i:s', $end),
        ];
    }

    private function firstError(Appointments $model)
    {
        $errors = $model->getFirstErrors();
        return $errors ? reset($errors) : 'Saving Error';
    }
}
