<?php

namespace frontend\controllers;

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
                        'matchCallback' => function () {
                            return Yii::$app->user->identity->role === 'consultant';
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
        $this->consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
        if ($this->consultant === null) {
            Yii::$app->session->setFlash('info', Yii::t('app', 'Create your consultant profile first, then set your working hours.'));
            $this->redirect(['consultant/create']);
            return false;
        }

        return true;
    }

    /** @var Consultant the logged-in consultant's profile */
    private $consultant;

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
                return $this->redirect(['index']);
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
            return $this->redirect(['index', '#' => 'time-off']);
        }

        return $this->renderIndex(new WeeklyScheduleForm($this->consultant), $timeOff);
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

        return $this->redirect(['index', '#' => 'time-off']);
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
            'timeOffForm' => $timeOffForm,
            'timeOff' => $timeOff,
        ]);
    }
}
