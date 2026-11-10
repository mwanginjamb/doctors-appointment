<?php

namespace frontend\controllers;


use yii\web\Controller;
use frontend\models\Consultant;
use yii\filters\VerbFilter;
use frontend\models\Appointments;
use yii\filters\AccessControl;
use frontend\models\AppointmentsSearch;
use frontend\components\FrontDesk;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

use Yii;

/**
 * AppointmentsController implements the CRUD actions for Appointments model.
 */
class AppointmentsController extends Controller
{
    /**
     * @inheritDoc
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                'verbs' => [
                    'class' => VerbFilter::class,
                    'actions' => [
                        'cancel' => ['POST'],
                        'outcome' => ['POST'],
                        'confirm' => ['POST'],
                    ],
                ],
                'access' => [
                    'class' => AccessControl::class,
                    'rules' => [
                        [ // logged in users
                            'allow' => true,
                            'roles' => ['@'],
                        ],
                    ],
                ]
            ]
        );
    }

    /**
     * Lists all Appointments models.
     *
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new AppointmentsSearch();
        $dataProvider = $searchModel->search($this->request->queryParams);
        // My Appointments: a consultant's own calendar, or a patient's own bookings (account type)
        if (Yii::$app->user->identity->isConsultantAccount()) {
            $dataProvider->query->andWhere(['consultant_id' => Yii::$app->user->id]);
        } else {
            $dataProvider->query->andWhere(['patient_id' => Yii::$app->user->id]);
        }

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Appointments model.
     * @param int $id ID
     * @return string
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        $appointment = $this->findModel($id, true);

        $appointment->patient_age = 0;
        // calculate age based on $model->patientProfile->dob in years and months
        if ($appointment->patientProfile && $appointment->patientProfile->dob) {
            $diff = date_diff(date_create($appointment->patientProfile->dob), date_create('today'));
            $appointment->patient_age = $diff->y . ' years ' . $diff->m . ' months';
        }

        return $this->render('view', [
            'model' => $appointment,
        ]);
    }

    /**
     * Creates a new Appointments model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return string|\yii\web\Response
     */
    public function actionCreate()
    {
        $model = new Appointments();
        $user = Yii::$app->user->identity;
        if ($user->isConsultantAccount()) {
            $model->consultant_id = $user->id;
        } else {
            $model->patient_id = $user->id;
        }

        if ($this->request->isPost) {
            if ($model->load($this->request->post()) && $model->saveBooking()) {
                return $this->redirect(['view', 'id' => $model->id]);
            }
        } else {
            $model->loadDefaultValues();
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing Appointments model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param int $id ID
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if ($this->request->isPost && $model->load($this->request->post()) && $model->saveBooking()) {
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Cancels an appointment the current user takes part in. The record is kept, marked
     * cancelled, for history and audits; appointments are never deleted from the app.
     * @param int $id ID
     * @return \yii\web\Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionCancel($id)
    {
        $model = $this->findModel($id, true);

        if ($model->cancel($this->request->post('note'))) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Appointment cancelled. It stays in the appointment history.'));
        } elseif ($model->hasErrors()) {
            Yii::$app->session->setFlash('error', $model->getFirstError('cancellation_note'));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Only upcoming scheduled or confirmed appointments can be cancelled.'));
        }

        // Back to where the Cancel button was: the list, the appointment, or the admin board with its filters
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Records whether the patient attended (outcome=completed) or did not come (outcome=no_show).
     * The consultant, their front desk or an admin may record it once the appointment has started,
     * and change it within Appointments::OUTCOME_CORRECTION_DAYS.
     */
    public function actionOutcome($id)
    {
        $model = $this->findModel($id, true);
        if (!FrontDesk::canRecordOutcome($model)) {
            throw new ForbiddenHttpException(Yii::t('app', 'Only the consultant or front desk can record whether the patient came.'));
        }

        $outcome = $this->request->post('outcome');
        if ($model->recordOutcome($outcome)) {
            Yii::$app->session->setFlash('success', $outcome === Appointments::STATUS_NO_SHOW
                ? Yii::t('app', 'Recorded as a no-show.')
                : Yii::t('app', 'Recorded as attended.'));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'An outcome can be recorded once the appointment has started, and changed within {n} days.', [
                'n' => Appointments::OUTCOME_CORRECTION_DAYS,
            ]));
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['view', 'id' => $model->id]);
    }

    public function actionCalendar($cid = null)
    {
        $model = new Appointments();
        $consultant = ($cid) ? Consultant::findOne($cid) : $cid;
        if (FrontDesk::isFrontDesk()) {
            if (!$consultant) {
                return $this->redirect(['board/index']);
            }
            if (!FrontDesk::canBookFor($consultant->user_id)) {
                throw new ForbiddenHttpException(Yii::t('app', 'You can only open the calendars of the consultants assigned to you.'));
            }
        }
        $user = Yii::$app->user->id;
        $booking_session = 'booking_session_' . $user;
        Yii::$app->session->set($booking_session, $cid); // carries relevant consultant id
        return $this->render('calendar', [
            'model' => $model,
            'consultant' => $consultant
        ]);
    }

    public function actionMyCalendar()
    {
        return $this->render('my-calendar');
    }

    // confirm appointment
    public function actionConfirm($id)
    {
        $model = $this->findModel($id);
        if (!Yii::$app->user->can(\common\components\RbacHelper::PERM_CONFIRM_APPOINTMENT, ['appointment' => $model])) {
            throw new ForbiddenHttpException(Yii::t('app', 'Only the consultant can confirm this appointment.'));
        }
        if ($model->status === Appointments::STATUS_SCHEDULED) {
            $model->status = Appointments::STATUS_CONFIRMED;
            if ($model->save(false)) {
                Yii::$app->session->setFlash('success', 'Appointment confirmed successfully.');
            } else {
                Yii::$app->session->setFlash('error', 'Error confirming appointment. Please try again.');
            }
        } else {
            Yii::$app->session->setFlash('error', 'Only scheduled appointments can be confirmed.');
        }
        return $this->redirect(['index']);
    }

    /**
     * Finds an Appointments model the current user takes part in.
     * If the model is not found, or belongs to someone else, a 404 HTTP exception will be thrown.
     * @param int $id ID
     * @param bool $allowStaff whether admins (any appointment) and front desk (their consultants'
     *                         appointments) may open it; for viewing and cancelling only
     * @return Appointments the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id, $allowStaff = false)
    {
        $model = Appointments::find()->where(['appointments.id' => $id])->one();
        if ($model !== null && ($model->isParticipant(Yii::$app->user->id) || ($allowStaff && FrontDesk::canHandle($model)))) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
