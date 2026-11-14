<?php

namespace frontend\controllers;

use common\components\Audit;
use common\models\AuditLog;
use frontend\components\FrontDesk;
use frontend\components\ThrottlesByIp;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\WaitlistEntry;
use frontend\models\WaitlistForm;
use frontend\services\Waitlist;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * F4 waiting list.
 *
 *  - The patient's link (/waitlist/<id>/<signature>, no sign-in): the free times in their window,
 *    book one, or leave the list.
 *  - Signed-in patients join from a consultant's calendar, or ask for something earlier than one of
 *    their appointments; they see their waits under "My waiting list".
 *  - The consultant and their front desk add patients and see who is waiting.
 */
class WaitlistController extends Controller
{
    use ThrottlesByIp;

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['offer', 'book', 'leave']],
                    ['allow' => true, 'actions' => ['join', 'mine'], 'roles' => ['@'], 'matchCallback' => function () {
                        return Yii::$app->user->identity->isPatientAccount();
                    }],
                    ['allow' => true, 'actions' => ['index', 'add', 'remove'], 'roles' => ['@'], 'matchCallback' => function () {
                        return FrontDesk::isStaff() || Yii::$app->user->identity->isConsultantAccount();
                    }],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                // POST only: opening the link (or a link preview fetching it) never changes anything
                'actions' => ['book' => ['POST'], 'leave' => ['POST'], 'remove' => ['POST']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!Waitlist::isEnabled()) {
            throw new NotFoundHttpException(Yii::t('app', 'The waiting list is not available.'));
        }

        return parent::beforeAction($action);
    }

    /** The patient's page: what they wait for, and the free times now. */
    public function actionOffer($id, $sig)
    {
        $this->throttle('waitlist');
        $entry = WaitlistEntry::verify($id, $sig);
        if ($entry === null) {
            return $this->render('invalid');
        }
        if ($entry->isWaiting() && $entry->endReason(Waitlist::now()) !== null) {
            $entry->end(WaitlistEntry::STATUS_EXPIRED);
        }

        return $this->render('offer', [
            'entry' => $entry,
            'sig' => $sig,
            'slots' => $entry->isWaiting() ? $entry->openSlots(Waitlist::bookableFrom($entry)) : [],
        ]);
    }

    public function actionBook($id, $sig)
    {
        $this->throttle('waitlist');
        $entry = WaitlistEntry::verify($id, $sig);
        if ($entry === null) {
            return $this->render('invalid');
        }
        $this->actAsPatient($entry);

        [$appointment, $error] = Waitlist::book($entry, (string) $this->request->post('date'), (string) $this->request->post('time'));
        if ($error !== null) {
            Yii::$app->session->setFlash('error', Html::encode($error));
        } else {
            Yii::$app->session->setFlash('success', $entry->wantsEarlier()
                ? Yii::t('app', 'Done: your appointment is moved to {when}. The clinic has been told.', ['when' => $this->when($appointment)])
                : Yii::t('app', 'Booked: you have an appointment on {when}.', ['when' => $this->when($appointment)]));
        }

        return $this->redirect(['offer', 'id' => $entry->id, 'sig' => $sig]);
    }

    public function actionLeave($id, $sig)
    {
        $this->throttle('waitlist');
        $entry = WaitlistEntry::verify($id, $sig);
        if ($entry === null) {
            return $this->render('invalid');
        }
        $this->actAsPatient($entry);
        if ($entry->end(WaitlistEntry::STATUS_LEFT)) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'You have left the waiting list. We will not message you about it again.'));
        }

        return $this->redirect(['offer', 'id' => $entry->id, 'sig' => $sig]);
    }

    /**
     * A signed-in patient joins: for a consultant (?cid=consultant.id) or for something earlier than
     * one of their appointments (?appointment=id).
     */
    public function actionJoin($cid = null, $appointment = null)
    {
        $patient = Yii::$app->user->identity;
        if ($appointment !== null) {
            $model = Appointments::findOne((int) $appointment);
            if ($model === null || (int) $model->patient_id !== (int) $patient->id) {
                throw new NotFoundHttpException(Yii::t('app', 'Appointment not found.'));
            }
            $form = new WaitlistForm(['appointment' => $model, 'patient' => $patient]);
        } else {
            $consultant = Consultant::findOne((int) $cid);
            if ($consultant === null || !$consultant->isTakingBookings()) {
                throw new NotFoundHttpException(Yii::t('app', 'Consultant not found.'));
            }
            $form = new WaitlistForm(['consultant' => $consultant, 'patient' => $patient]);
        }

        if ($form->load($this->request->post()) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', "You are on the waiting list. We will message you when a time frees up; the free times right now are below."));
            return $this->redirect(['offer', 'id' => $form->entry->id, 'sig' => $form->entry->signature()]);
        }

        return $this->render('join', ['form' => $form]);
    }

    /** The signed-in patient's waits. */
    public function actionMine()
    {
        $entries = WaitlistEntry::find()
            ->where(['patient_id' => Yii::$app->user->id])
            ->with(['consultant', 'facility', 'appointment'])
            ->orderBy(['status' => SORT_DESC, 'created_at' => SORT_DESC]) // waiting first
            ->limit(30)
            ->all();

        return $this->render('mine', ['entries' => $entries]);
    }

    /** Staff: who is waiting for the consultants they handle. */
    public function actionIndex()
    {
        $entries = $this->staffScope(WaitlistEntry::find()->alias('w'))
            ->andWhere(['w.status' => WaitlistEntry::STATUS_WAITING])
            ->with(['patient', 'consultant', 'facility', 'appointment'])
            ->orderBy(['w.created_at' => SORT_ASC])
            ->limit(200)
            ->all();

        return $this->render('index', ['entries' => $entries, 'consultants' => $this->bookableConsultants()]);
    }

    /**
     * Staff add a patient: for a consultant (?cid=consultant.id), or for something earlier than an
     * appointment (?appointment=id).
     */
    public function actionAdd($cid = null, $appointment = null)
    {
        if ($appointment !== null) {
            $model = Appointments::findOne((int) $appointment);
            if ($model === null || !FrontDesk::canBookAppointment($model)) {
                throw new NotFoundHttpException(Yii::t('app', 'Appointment not found.'));
            }
            $form = new WaitlistForm(['appointment' => $model, 'byStaff' => true]);
        } else {
            $form = new WaitlistForm(['consultant' => $this->findBookableConsultant($cid), 'byStaff' => true]);
        }

        if ($form->load($this->request->post()) && $form->save()) {
            $channels = Waitlist::sendWelcome($form->entry);
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} is on the waiting list for {doctor}.{told}', [
                'name' => Html::encode($form->patient->full_name ?: $form->patient->username),
                'doctor' => Html::encode($form->consultant->names),
                'told' => $channels ? ' ' . Yii::t('app', 'They were sent their link by {channels}.', ['channels' => implode(' and ', $channels)]) : '',
            ]));
            return $this->redirect(['index']);
        }

        return $this->render('add', ['form' => $form]);
    }

    public function actionRemove($id)
    {
        $entry = $this->staffScope(WaitlistEntry::find()->alias('w'))->andWhere(['w.id' => (int) $id])->one();
        if ($entry === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Waiting-list entry not found.'));
        }
        if ($entry->end(WaitlistEntry::STATUS_LEFT)) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} is off the waiting list.', [
                'name' => Html::encode($entry->patient->full_name ?? 'The patient'),
            ]));
        }

        return $this->redirect(['index']);
    }

    /** @param \yii\db\ActiveQuery $query aliased 'w' */
    private function staffScope($query)
    {
        return WaitlistEntry::scopeToStaff($query);
    }

    /**
     * @return Consultant[] who the current user may add patients for: the consultant themselves, or
     *                      the consultants their front desk books for
     */
    private function bookableConsultants()
    {
        $candidates = Yii::$app->user->identity->isConsultantAccount()
            ? Consultant::find()->where(['user_id' => Yii::$app->user->id])->all()
            : Consultant::find()->andFilterWhere(['user_id' => FrontDesk::consultantUserIds() ?? null])->orderBy(['names' => SORT_ASC])->all();

        return array_values(array_filter($candidates, function (Consultant $consultant) {
            return $consultant->user_id !== null && $consultant->isTakingBookings() && FrontDesk::canBookFor($consultant->user_id);
        }));
    }

    private function findBookableConsultant($cid)
    {
        $consultants = $this->bookableConsultants();
        if (!$consultants) {
            throw new ForbiddenHttpException(Yii::t('app', 'You have no consultant to keep a waiting list for.'));
        }
        if ($cid === null) {
            return $consultants[0];
        }
        foreach ($consultants as $consultant) {
            if ((int) $consultant->id === (int) $cid) {
                return $consultant;
            }
        }

        throw new ForbiddenHttpException(Yii::t('app', 'You can only add patients to the waiting lists of your own consultants.'));
    }

    /** Changes made through the link are logged as the patient using their link. */
    private function actAsPatient(WaitlistEntry $entry)
    {
        $patient = $entry->patient;
        Audit::actAs(AuditLog::ACTOR_SELF_SERVICE, $entry->patient_id, $patient ? ($patient->full_name ?: $patient->username) : null);
    }

    private function when(Appointments $appointment)
    {
        return date('l j F \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time));
    }
}
