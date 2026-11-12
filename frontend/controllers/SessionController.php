<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use frontend\models\Consultant;
use frontend\models\ConsultantSession;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * One-off sessions (F8 phase 3): a consultant sees patients at a facility on one date, outside
 * their weekly pattern.
 *
 * The consultant manages their own; front desk the sessions of their consultants at their facility;
 * facility admins those at their facility; consultancy admins any (permission
 * manageConsultantSessions, checked for each facility). Someone else's changes are sent to the
 * consultant.
 */
class SessionController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['delete' => ['POST']],
            ],
        ];
    }

    /**
     * Upcoming sessions of a consultant, and a form to add one.
     *
     * @param int|null $cid consultant.id; a consultant's own when left out
     */
    public function actionIndex($cid = null)
    {
        $consultant = $this->findConsultant($cid);
        $facilities = $this->facilityChoices($consultant);
        if (!$facilities) {
            throw new ForbiddenHttpException(Yii::t('app', "You cannot add sessions to this consultant's calendar."));
        }

        $session = new ConsultantSession(['consultant_id' => $consultant->id]);
        if (count($facilities) === 1) {
            $session->facility_id = (int) key($facilities);
        }
        if ($this->request->isPost) {
            $session->load($this->request->post());
            $session->consultant_id = $consultant->id;
            if (!isset($facilities[(int) $session->facility_id])) {
                $session->addError('facility_id', Yii::t('app', 'Choose one of the listed facilities.'));
            } elseif ($session->save()) {
                $session->notifyConsultant();
                Yii::$app->session->setFlash('success', Yii::t('app', 'Session added: {session}. Patients can be booked into it now.', [
                    'session' => Html::encode($session->getLabel()),
                ]));
                return $this->redirect(['index', 'cid' => $cid]);
            }
        }

        $sessions = ConsultantSession::find()->with('facility')
            ->where(['consultant_id' => $consultant->id, 'facility_id' => array_keys($facilities)])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->orderBy(['date' => SORT_ASC, 'start_time' => SORT_ASC])->all();
        $booked = [];
        foreach ($sessions as $row) {
            $booked[$row->id] = (int) $row->bookedAppointments()->count();
        }

        return $this->render('index', [
            'consultant' => $consultant,
            'session' => $session,
            'sessions' => $sessions,
            'booked' => $booked,
            'facilities' => $facilities,
            'cid' => $cid,
        ]);
    }

    /**
     * Removes a session nobody is booked into yet.
     */
    public function actionDelete($id)
    {
        $session = ConsultantSession::findOne($id);
        if ($session === null || !$this->canManage($session->consultant, $session->facility_id)) {
            throw new NotFoundHttpException(Yii::t('app', 'Session not found.'));
        }
        $cid = (int) $session->consultant->user_id === (int) Yii::$app->user->id ? null : $session->consultant_id;
        $booked = (int) $session->bookedAppointments()->count();
        if ($booked > 0) {
            Yii::$app->session->setFlash('error', Yii::t('app', '{n} appointment(s) are booked in this session. Move or cancel them first.', ['n' => $booked]));
        } else {
            $session->delete();
            $session->notifyConsultant(true);
            Yii::$app->session->setFlash('success', Yii::t('app', 'Session removed: {session}.', ['session' => Html::encode($session->getLabel())]));
        }

        return $this->redirect(['index', 'cid' => $cid]);
    }

    private function canManage(?Consultant $consultant, $facilityId)
    {
        return $consultant !== null
            && Yii::$app->user->can(RbacHelper::PERM_MANAGE_SESSIONS, ['consultant' => $consultant, 'facilityId' => (int) $facilityId]);
    }

    /**
     * The consultant's facilities taking bookings where the user may add their sessions, as
     * id => name.
     */
    private function facilityChoices(Consultant $consultant)
    {
        $choices = [];
        foreach ($consultant->engagements as $engagement) {
            if ($engagement->facility->isActive() && $this->canManage($consultant, $engagement->facility_id)) {
                $choices[(int) $engagement->facility_id] = $engagement->facility->name;
            }
        }

        return $choices;
    }

    private function findConsultant($cid)
    {
        $consultant = $cid !== null ? Consultant::findOne((int) $cid) : Consultant::findOne(['user_id' => Yii::$app->user->id]);
        if ($consultant === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Consultant not found.'));
        }

        return $consultant;
    }
}
