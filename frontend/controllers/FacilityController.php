<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use frontend\components\AdminAccessControl;
use frontend\models\Appointments;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\Facility;
use frontend\models\FacilityClosure;
use frontend\models\FacilityRequest;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Admin list of facilities: the places consultants see patients. Consultants choose from these on
 * their Availability page, and ask for missing ones (facility requests), which an admin creates or
 * declines here.
 */
class FacilityController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AdminAccessControl::class,
                'permission' => RbacHelper::PERM_MANAGE_REFERENCE_DATA,
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['delete' => ['POST'], 'decline' => ['POST'], 'delete-closure' => ['POST']],
            ],
        ];
    }

    public function actionIndex()
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Facility::find()->with('engagements.consultant'),
            'sort' => ['defaultOrder' => ['name' => SORT_ASC], 'attributes' => ['name', 'ownership', 'active']],
            'pagination' => ['pageSize' => 100],
        ]);

        return $this->render('index', ['dataProvider' => $dataProvider, 'pendingRequests' => FacilityRequest::pendingCount()]);
    }

    /**
     * Facilities consultants asked for: those waiting, oldest first, then the last 30 days' answers.
     */
    public function actionRequests()
    {
        $pending = FacilityRequest::find()
            ->where(['status' => FacilityRequest::STATUS_PENDING])
            ->with('consultant')
            ->orderBy(['created_at' => SORT_ASC])
            ->all();
        $answered = FacilityRequest::find()
            ->where(['not', ['status' => FacilityRequest::STATUS_PENDING]])
            ->andWhere(['>=', 'decided_at', time() - 30 * 86400])
            ->with(['consultant', 'facility'])
            ->orderBy(['decided_at' => SORT_DESC])
            ->all();

        return $this->render('requests', ['pending' => $pending, 'answered' => $answered]);
    }

    /**
     * Creates the facility a consultant asked for, from their details as checked and corrected by
     * the admin. The consultant is added there and told.
     */
    public function actionCreateFromRequest($id)
    {
        $request = $this->findRequest($id);
        if (!$request->isPending()) {
            return $this->alreadyAnswered();
        }
        $model = $request->toFacility();
        if ($this->request->isPost) {
            $model = new Facility(['active' => true]);
            $model->load($this->request->post());
            if ($request->approve($model)) {
                Yii::$app->session->setFlash('success', Yii::t('app', '{name} added, and {doctor} now works there. They have been told.', [
                    'name' => Html::encode($model->name),
                    'doctor' => Html::encode($request->consultant->names ?? 'the consultant'),
                ]));
                return $this->redirect(['requests']);
            }
        }

        return $this->render('create', ['model' => $model, 'facilityRequest' => $request]);
    }

    /**
     * Declines a request, with an optional reason that is sent to the consultant.
     */
    public function actionDecline($id)
    {
        $request = $this->findRequest($id);
        if (!$request->isPending()) {
            return $this->alreadyAnswered();
        }
        if ($request->decline($this->request->post('reason'))) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Request for {name} declined. {doctor} has been told.', [
                'name' => Html::encode($request->name),
                'doctor' => Html::encode($request->consultant->names ?? 'The consultant'),
            ]));
        } else {
            $errors = $request->getFirstErrors();
            Yii::$app->session->setFlash('error', $errors ? reset($errors) : Yii::t('app', 'The request could not be declined.'));
        }

        return $this->redirect(['requests']);
    }

    private function findRequest($id)
    {
        $request = FacilityRequest::findOne($id);
        if ($request === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Request not found.'));
        }

        return $request;
    }

    /** Another admin answered first (or the page was old): nothing to do. */
    private function alreadyAnswered()
    {
        Yii::$app->session->setFlash('info', Yii::t('app', 'That request has already been answered.'));

        return $this->redirect(['requests']);
    }

    public function actionCreate()
    {
        // A new facility takes bookings unless the admin unticks it
        $model = new Facility(['active' => true]);
        if ($model->load($this->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} added. Consultants can now add it on their Availability page.', ['name' => $model->name]));
            return $this->redirect(['index']);
        }

        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $wasActive = $model->isActive();
        if ($model->load($this->request->post()) && $model->save()) {
            $session = Yii::$app->session;
            $session->setFlash('success', Yii::t('app', '{name} saved.', ['name' => $model->name]));
            if ($wasActive && !$model->isActive()) {
                $upcoming = $this->upcomingAppointments($model)->count();
                $session->addFlash('warning', Yii::t('app', 'Nobody can book {name} now. {n} upcoming appointment(s) there are kept; their consultants need to move or cancel them.', [
                    'name' => $model->name,
                    'n' => $upcoming,
                ]));
            }
            return $this->redirect(['index']);
        }

        return $this->render('update', ['model' => $model]);
    }

    /**
     * Only a facility nobody has used can be deleted; otherwise it is marked as not taking bookings.
     */
    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $used = ConsultantFacility::find()->where(['facility_id' => $model->id])->exists()
            || ConsultantWorkingHours::find()->where(['facility_id' => $model->id])->exists()
            || Appointments::find()->where(['facility_id' => $model->id])->exists();
        if ($used) {
            Yii::$app->session->setFlash('error', Yii::t('app', '{name} has consultants or appointments, so it is kept. Untick "Taking bookings" to close it instead.', ['name' => $model->name]));
            return $this->redirect(['index']);
        }

        $model->delete();
        Yii::$app->session->setFlash('success', Yii::t('app', '{name} removed.', ['name' => $model->name]));

        return $this->redirect(['index']);
    }

    /**
     * Days the facility is closed (F8 phase 2): the upcoming ones, and a form to add one. Nobody
     * can be booked there on those days; appointments already booked are kept and listed for staff
     * to move.
     */
    public function actionClosures($id)
    {
        $facility = $this->findModel($id);
        $closure = new FacilityClosure(['facility_id' => $facility->id]);
        if ($closure->load($this->request->post()) && $closure->save()) {
            $session = Yii::$app->session;
            $session->setFlash('success', Yii::t('app', '{name} is closed on {date}.', [
                'name' => Html::encode($facility->name),
                'date' => date('l j M Y', strtotime($closure->date)),
            ]));
            $booked = $this->upcomingAppointments($facility)->andWhere(['date' => $closure->date])->count();
            if ($booked > 0) {
                $session->addFlash('warning', Yii::t('app', '{n} appointment(s) are already booked there that day. They are kept: {link} to move or cancel them.', [
                    'n' => $booked,
                    'link' => Html::a(Yii::t('app', 'open them on the board'), ['board/index', 'facility' => $facility->id, 'from' => $closure->date, 'to' => $closure->date]),
                ]));
            }
            return $this->redirect(['closures', 'id' => $facility->id]);
        }

        $closures = FacilityClosure::find()->where(['facility_id' => $facility->id])
            ->andWhere(['>=', 'date', date('Y-m-d')])->orderBy(['date' => SORT_ASC])->all();
        $booked = [];
        if ($closures) {
            $rows = $this->upcomingAppointments($facility)->select(['date', 'n' => 'COUNT(*)'])
                ->andWhere(['date' => array_column($closures, 'date')])->groupBy('date')->orderBy(null)->asArray()->all();
            $booked = array_column($rows, 'n', 'date');
        }

        return $this->render('closures', ['facility' => $facility, 'closure' => $closure, 'closures' => $closures, 'booked' => $booked]);
    }

    /**
     * Opens the facility again on a day it was closed.
     */
    public function actionDeleteClosure($id)
    {
        $closure = FacilityClosure::findOne($id);
        if ($closure === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Closure not found.'));
        }
        $closure->delete();
        Yii::$app->session->setFlash('success', Yii::t('app', '{name} is open again on {date}.', [
            'name' => Html::encode($closure->facility->name ?? ''),
            'date' => date('l j M Y', strtotime($closure->date)),
        ]));

        return $this->redirect(['closures', 'id' => $closure->facility_id]);
    }

    private function upcomingAppointments(Facility $facility)
    {
        return Appointments::find()
            ->where(['facility_id' => $facility->id])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]]);
    }

    protected function findModel($id)
    {
        if (($model = Facility::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
