<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use frontend\models\Consultant;
use frontend\models\ConsultantTimeOff;
use frontend\models\TimeOffForm;
use frontend\models\WeeklyScheduleForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Lets a consultant set the weekly hours patients can book, and the time off when they can't.
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
                    'session-length' => ['POST'],
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
     * Sets the length of new bookings. Existing appointments keep the length they were booked with.
     */
    public function actionSessionLength()
    {
        $minutes = (int) $this->request->post('minutes');
        $allowed = Yii::$app->params['booking.sessionLengths'] ?? [30];

        if (!in_array($minutes, $allowed, true)) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Choose one of the listed session lengths.'));
        } else {
            // Only this column: the rest of the profile may not pass the full profile rules yet
            $this->consultant->updateAttributes(['appointment_session_duration' => $minutes, 'updated_at' => time()]);
            Yii::$app->session->setFlash('success', Yii::t('app', 'New bookings now last {m} minutes. Appointments already booked keep their length.', ['m' => $minutes]));
        }

        return $this->redirect($this->route('index'));
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

    private function renderIndex(WeeklyScheduleForm $form, TimeOffForm $timeOffForm)
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
        ]);
    }
}
