<?php

namespace frontend\controllers;

use common\models\User;
use frontend\components\AdminAccessControl;
use frontend\components\FrontDesk;
use frontend\models\RegisterPatientForm;
use frontend\models\Appointments;
use frontend\models\Consultant;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
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
                    'find-patient' => ['GET'],
                    'register-patient' => ['POST'],
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
        } elseif (FrontDesk::isStaff()) {
            // The consultant whose calendar staff opened from the appointments board
            $consultant = Consultant::findOne(Yii::$app->session->get('booking_session_' . $user->id));
            if ($consultant && (AdminAccessControl::isAdmin() || FrontDesk::canBookFor($consultant->user_id))) {
                $appointments = Appointments::find()
                    ->where(['consultant_id' => $consultant->user_id])
                    ->with(['consultant', 'patient'])
                    ->all();
            }
        }

        $events = [];
        foreach ($appointments as $app) {
            $patientName = $app->patient->full_name ?? 'Patient';
            $consultantName = $app->consultant->names ?? 'Dr.';
            $event = [
                'id' => $app->id,
                'title' => $patientName . ' Appointment with ' . $consultantName,
                'description' => ' Subject: ' . mb_substr((string) $app->symptoms_brief, 0, 200),
            ];
            // Cancelled appointments stay visible as history: greyed out and not draggable
            if ($app->status === Appointments::STATUS_CANCELLED) {
                $event['title'] = 'Cancelled: ' . $event['title'];
                if ($app->cancellation_note) {
                    $event['description'] = ' Cancelled: ' . mb_substr($app->cancellation_note, 0, 200);
                }
                $event['color'] = '#adb5bd';
                $event['editable'] = false;
            }
            $events[] = $event + $this->eventTimes($app);
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
        // The logged-in user's own side of the booking always comes from the session
        $patient = $data['patient'] ?? null;
        if ($user->role === 'consultant') {
            // A consultant books into their own calendar, for a patient found with api/find-patient
            $model->consultant_id = $user->id;
            $model->patient_id = ctype_digit((string) $patient) ? (int) $patient : null;
        } elseif (FrontDesk::isFrontDesk()) {
            // Front desk books for a patient into the calendar of a consultant assigned to them
            if (!FrontDesk::canBookFor($data['consultant'] ?? 0)) {
                return ['status' => 'error', 'message' => 'You can only book for the consultants assigned to you.'];
            }
            $model->consultant_id = (int) $data['consultant'];
            $model->patient_id = ctype_digit((string) $patient) ? (int) $patient : null;
        } else {
            $model->patient_id = $user->id;
            $model->consultant_id = $data['consultant'] ?? null;
        }

        if ($model->saveBooking()) {
            return ['status' => 'success', 'message' => 'Appointment #' . $model->id . ' booked successfully!'];
        }

        return ['status' => 'error', 'message' => $this->firstError($model)];
    }

    public function actionUpdateVisit()
    {
        $data = $this->getJsonBody();

        $model = Appointments::findOne($data['id'] ?? null);
        if (!$model || !($model->isParticipant(Yii::$app->user->id) || FrontDesk::canBookFor($model->consultant_id))) {
            return ['status' => 'error', 'message' => 'Appointment not found.'];
        }

        $model->date = $data['date'] ?? null;
        $model->time = $data['time'] ?? null;

        if ($model->saveBooking()) {
            return ['status' => 'success', 'message' => 'Appointment updated'];
        }

        return ['status' => 'error', 'message' => $this->firstError($model)];
    }

    /**
     * Lets a consultant or front desk find the patient they are booking for, by exact email or
     * phone number. There is deliberately no name search: staff should not be able to browse every
     * patient on the platform. Matches are returned with masked contact details.
     *
     * GET api/find-patient?q=<email or phone>
     */
    public function actionFindPatient($q = '')
    {
        $this->requireBookingStaff();

        $q = trim((string) $q);
        $digits = preg_replace('/\D/', '', $q);

        if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
            $match = ['email' => $q];
        } elseif (strlen($digits) >= 9) {
            // Compare the last 9 digits so 0712…, 254712… and +254 712… find the same number
            $match = ['like', 'phone_number', '%' . substr($digits, -9), false];
        } else {
            return ['status' => 'error', 'message' => "Enter the patient's full email address or phone number."];
        }

        $patients = User::find()
            ->select(['id', 'full_name', 'username', 'email', 'phone_number'])
            ->where($match)
            ->andWhere(['status' => User::STATUS_ACTIVE])
            ->andWhere(['or', ['role' => 'client'], ['role' => null], ['role' => '']])
            ->limit(10)
            ->all();

        if (!$patients) {
            return ['status' => 'error', 'message' => 'No registered patient has that email or phone number. Ask them to sign up first.'];
        }

        return [
            'status' => 'success',
            'patients' => array_map([$this, 'patientSummary'], $patients),
        ];
    }

    /**
     * Registers a walk-in patient who has no account (name and phone, email optional) and returns
     * them like find-patient does. When active patients already use the phone, it returns
     * status "exists" with them, so staff choose one or resend with confirm_new to add someone new.
     *
     * POST api/register-patient {full_name, phone, email?, confirm_new?}
     */
    public function actionRegisterPatient()
    {
        $this->requireBookingStaff();

        $form = new RegisterPatientForm();
        $form->setAttributes($this->getJsonBody());
        $patient = $form->register();

        if ($patient) {
            return ['status' => 'success', 'patient' => $this->patientSummary($patient)];
        }
        if ($form->matches) {
            return [
                'status' => 'exists',
                'message' => 'Patients with this phone number are already registered. Choose one, or register a new patient anyway.',
                'patients' => array_map([$this, 'patientSummary'], $form->matches),
            ];
        }
        $errors = $form->getFirstErrors();

        return ['status' => 'error', 'message' => $errors ? reset($errors) : 'Could not register the patient.'];
    }

    /**
     * Only consultants and front desk book on a patient's behalf.
     */
    private function requireBookingStaff()
    {
        if (Yii::$app->user->identity->role !== 'consultant' && !FrontDesk::isFrontDesk()) {
            throw new ForbiddenHttpException('Only consultants and front desk can look up or register patients.');
        }
    }

    /** Not an action (no "action" prefix); public so array_map can call it. */
    public function patientSummary(User $p)
    {
        return [
            'id' => $p->id,
            'name' => $p->full_name ?: $p->username,
            'email' => self::mask($p->email, '@'),
            'phone' => $p->phone_number ? '••••' . substr($p->phone_number, -3) : null,
            // Staff only (both callers require consultant or front desk): no-shows in the last 12 months
            'noShows' => Appointments::countNoShows($p->id),
        ];
    }

    /**
     * Keeps the first two characters and everything from $from on: "jane@x.com" -> "ja••@x.com".
     */
    private static function mask($value, $from)
    {
        $pos = strpos((string) $value, $from);
        if ($pos === false) {
            return null;
        }

        return substr($value, 0, min(2, $pos)) . '••' . substr($value, $pos);
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
