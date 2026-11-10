<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\models\WeeklyScheduleForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Lets a consultant set the weekly hours patients can book.
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
                'actions' => ['index' => ['GET', 'POST']],
            ],
        ];
    }

    public function actionIndex()
    {
        $consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
        if ($consultant === null) {
            Yii::$app->session->setFlash('info', Yii::t('app', 'Create your consultant profile first, then set your working hours.'));
            return $this->redirect(['consultant/create']);
        }

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

        return $this->render('index', [
            'form' => $form,
            'consultant' => $consultant,
        ]);
    }
}
