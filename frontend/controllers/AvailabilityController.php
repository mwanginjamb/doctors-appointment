<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantWorkingHours;
use frontend\models\ConsultantTimeOff;
use frontend\models\FacilityRequest;
use frontend\models\TimeOffForm;
use frontend\models\WeeklyScheduleForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Lets a consultant set the facilities they work at (with the session length and fee at each), the
 * weekly hours at each facility, and the time off when they can't be booked.
 */
class AvailabilityController extends Controller
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
                        // Consultants for their own calendar (rule isTheConsultant), admins for anyone's
                        'matchCallback' => function () {
                            return Yii::$app->user->can(RbacHelper::PERM_MANAGE_AVAILABILITY, ['consultantUserId' => Yii::$app->user->id]);
                        },
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET', 'POST'],
                    'add-time-off' => ['POST'],
                    'delete-time-off' => ['POST'],
                    'add-facility' => ['POST'],
                    'update-facility' => ['POST'],
                    'remove-facility' => ['POST'],
                    'request-facility' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * @param \yii\base\Action $action
     */
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Someone else's availability with ?cid=<consultant.id>, for whoever may manage it (admins)
        $cid = $this->request->get('cid');
        if ($cid !== null) {
            $other = Consultant::findOne($cid);
            if ($other === null && Yii::$app->user->can(RbacHelper::PERM_MANAGE_CONSULTANTS)) {
                throw new NotFoundHttpException(Yii::t('app', 'Consultant not found.'));
            }
            if ($other !== null && (int) $other->user_id !== (int) Yii::$app->user->id
                && Yii::$app->user->can(RbacHelper::PERM_MANAGE_AVAILABILITY, ['consultant' => $other])) {
                $this->consultant = $other;
                $this->managedCid = (int) $other->id;
                return true;
            }
            // Otherwise ?cid= is ignored: a consultant always gets their own page
        }

        $this->consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
        if ($this->consultant === null && Yii::$app->user->can(RbacHelper::PERM_MANAGE_CONSULTANTS)) {
            $this->redirect(['manage-consultants/index']);
            return false;
        }
        if ($this->consultant === null) {
            Yii::$app->session->setFlash('info', Yii::t('app', 'Create your consultant profile first, then set your working hours.'));
            $this->redirect(['consultant/create']);
            return false;
        }

        return true;
    }

    /** @var Consultant the logged-in consultant's profile, or the one an admin is managing */
    private $consultant;

    /** @var int|null consultant.id when an admin manages someone else's availability */
    private $managedCid;

    /**
     * A route on this page, keeping ?cid= while an admin manages another consultant.
     */
    private function route($action, array $params = [])
    {
        return array_merge([$action], $params, $this->managedCid ? ['cid' => $this->managedCid] : []);
    }

    public function actionIndex()
    {
        $consultant = $this->consultant;
        $form = new WeeklyScheduleForm($consultant);
        if ($this->request->isPost && $form->load($this->request->post())) {
            if ($form->save()) {
                $session = Yii::$app->session;
                $session->setFlash('success', Yii::t('app', 'Working hours saved.'));
                if ($consultant->getHoursSummary() === null) {
                    $session->addFlash('warning', Yii::t('app', 'Every day is closed, so patients cannot book you until you add some hours.'));
                }
                $outside = count($form->findAppointmentsOutsideHours());
                if ($outside > 0) {
                    $session->addFlash('warning', Yii::t('app', '{n} upcoming appointment(s) now fall outside your hours. They are kept; reschedule or cancel them from My Appointments if needed.', ['n' => $outside]));
                }
                return $this->redirect($this->route('index'));
            }
        }

        return $this->renderIndex($form, new TimeOffForm());
    }

    /**
     * Adds time off. It is saved even when appointments are already booked in it;
     * the page then lists those so the consultant can reschedule or cancel each one.
     */
    public function actionAddTimeOff()
    {
        $timeOff = new TimeOffForm();
        if ($timeOff->load($this->request->post()) && $timeOff->save($this->consultant)) {
            $session = Yii::$app->session;
            $session->setFlash('success', Yii::t('app', 'Time off added: {period}.', ['period' => $timeOff->timeOff->getLabel()]));
            $clashes = count($timeOff->timeOff->findClashingAppointments());
            if ($clashes > 0) {
                $session->addFlash('warning', Yii::t('app', '{n} appointment(s) are already booked during this time. They are listed under Time off; reschedule or cancel each one.', ['n' => $clashes]));
            }
            return $this->redirect($this->route('index', ['#' => 'time-off']));
        }

        return $this->renderIndex(new WeeklyScheduleForm($this->consultant), $timeOff);
    }

    /**
     * Adds a facility the consultant works at. Hours there are added in the weekly schedule.
     */
    public function actionAddFacility()
    {
        $engagement = new ConsultantFacility(['session_minutes' => $this->consultant->getSessionMinutes()]);
        $engagement->load($this->request->post());
        $engagement->consultant_id = $this->consultant->id;
        if ($engagement->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{facility} added. Add your hours there under Weekly hours.', [
                'facility' => $engagement->facility->name,
            ]));
            return $this->redirect($this->route('index', ['#' => 'weekly-hours']));
        }

        return $this->renderIndex(new WeeklyScheduleForm($this->consultant), new TimeOffForm(), $engagement);
    }

    /**
     * Changes the session length, fee, online booking or dates at one facility. Appointments
     * already booked keep the length and fee they were booked with.
     */
    public function actionUpdateFacility($id)
    {
        $engagement = $this->findEngagement($id);
        $facilityId = $engagement->facility_id;
        $engagement->load($this->request->post());
        $engagement->facility_id = $facilityId; // the facility itself is not changed here
        if ($engagement->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{facility} saved. Appointments already booked keep their length and fee.', [
                'facility' => $engagement->facility->name,
            ]));
            return $this->redirect($this->route('index', ['#' => 'facilities']));
        }
        $errors = $engagement->getFirstErrors();
        Yii::$app->session->setFlash('error', $engagement->facility->name . ': ' . reset($errors));

        return $this->redirect($this->route('index', ['#' => 'facilities']));
    }

    /**
     * Stops working at a facility. Refused while there are hours or upcoming appointments there,
     * and for the last facility.
     */
    public function actionRemoveFacility($id)
    {
        $engagement = $this->findEngagement($id);
        $name = $engagement->facility->name;
        $session = Yii::$app->session;

        $upcoming = Appointments::find()
            ->where(['consultant_id' => $this->consultant->user_id, 'facility_id' => $engagement->facility_id])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->count();
        if (count($this->consultant->engagements) <= 1) {
            $session->setFlash('error', Yii::t('app', '{facility} is your only facility. Add another before removing it.', ['facility' => $name]));
        } elseif (ConsultantWorkingHours::find()->where(['consultant_id' => $this->consultant->id, 'facility_id' => $engagement->facility_id])->exists()) {
            $session->setFlash('error', Yii::t('app', 'Remove your weekly hours at {facility} first.', ['facility' => $name]));
        } elseif ($upcoming > 0) {
            $session->setFlash('error', Yii::t('app', '{n} upcoming appointment(s) are at {facility}. Move or cancel them first.', ['n' => $upcoming, 'facility' => $name]));
        } else {
            $engagement->delete();
            $session->setFlash('success', Yii::t('app', '{facility} removed.', ['facility' => $name]));
        }

        return $this->redirect($this->route('index', ['#' => 'facilities']));
    }

    /**
     * Asks the administrator to list a facility that is not on the list yet. When it is created,
     * the consultant is added there; either way they are told by email and WhatsApp.
     */
    public function actionRequestFacility()
    {
        $request = new FacilityRequest();
        $request->load($this->request->post());
        $request->consultant_id = $this->consultant->id;
        if ($request->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Request for {name} sent. You will be told by email and WhatsApp when the administrator answers.', [
                'name' => \yii\helpers\Html::encode($request->name),
            ]));
            return $this->redirect($this->route('index', ['#' => 'facilities']));
        }

        return $this->renderIndex(new WeeklyScheduleForm($this->consultant), new TimeOffForm(), null, $request);
    }

    private function findEngagement($id)
    {
        $engagement = ConsultantFacility::findOne(['id' => $id, 'consultant_id' => $this->consultant->id]);
        if ($engagement === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Facility not found.'));
        }

        return $engagement;
    }

    public function actionDeleteTimeOff($id)
    {
        $timeOff = ConsultantTimeOff::findOne(['id' => $id, 'consultant_id' => $this->consultant->id]);
        if ($timeOff === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Time off not found.'));
        }
        $label = $timeOff->getLabel();
        $timeOff->delete();
        Yii::$app->session->setFlash('success', Yii::t('app', 'Time off removed: {period}.', ['period' => $label]));

        return $this->redirect($this->route('index', ['#' => 'time-off']));
    }

    private function renderIndex(WeeklyScheduleForm $form, TimeOffForm $timeOffForm, ?ConsultantFacility $newEngagement = null, ?FacilityRequest $facilityRequest = null)
    {
        // Current and upcoming time off, each with the appointments booked during it
        $timeOff = ConsultantTimeOff::find()
            ->where(['consultant_id' => $this->consultant->id])
            ->andWhere(['>', 'end_at', date('Y-m-d H:i:s')])
            ->orderBy(['start_at' => SORT_ASC])
            ->all();

        return $this->render('index', [
            'form' => $form,
            'consultant' => $this->consultant,
            'cid' => $this->managedCid,
            'timeOffForm' => $timeOffForm,
            'timeOff' => $timeOff,
            'newEngagement' => $newEngagement ?? new ConsultantFacility(['session_minutes' => $this->consultant->getSessionMinutes()]),
            'facilityRequest' => $facilityRequest ?? new FacilityRequest(),
            // Waiting, and answered in the last 30 days
            'facilityRequests' => FacilityRequest::find()
                ->where(['consultant_id' => $this->consultant->id])
                ->andWhere(['or', ['status' => FacilityRequest::STATUS_PENDING], ['>=', 'decided_at', time() - 30 * 86400]])
                ->orderBy(['created_at' => SORT_DESC])
                ->all(),
        ]);
    }
}
