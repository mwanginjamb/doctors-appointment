<?php

namespace backend\controllers;

use backend\models\AdminLoginForm;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use frontend\models\IncentivePayout;
use frontend\models\PatientCredit;
use frontend\services\Pilot;
use Yii;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['login', 'error'],
                        'allow' => true,
                    ],
                    [
                        'actions' => ['logout', 'index'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => \yii\web\ErrorAction::class,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex()
    {
        // What waits for a platform admin
        $toApprove = [];
        $unsigned = [];
        $withAgreement = FacilityAgreement::find()->select('facility_id')->distinct()->column();
        foreach (Facility::find()->where(['or', ['active' => 1], ['id' => $withAgreement]])->orderBy('name')->all() as $facility) {
            if (Pilot::termsStatus($facility->id) === Pilot::TERMS_AWAITING_APPROVAL) {
                $toApprove[] = $facility;
            }
            // Taking bookings with consultants there, but no agreement on record
            if ($facility->active && !in_array($facility->id, $withAgreement) && $facility->getEngagements()->exists()) {
                $unsigned[] = $facility;
            }
        }

        return $this->render('index', [
            'refunds' => (int) PatientCredit::find()->where(['status' => PatientCredit::STATUS_REFUND_REQUESTED])->count(),
            'payoutsReady' => (int) IncentivePayout::find()->where(['status' => IncentivePayout::STATUS_READY])->count(),
            'payoutsHeld' => (int) IncentivePayout::find()->where(['status' => IncentivePayout::STATUS_HELD])->count(),
            'overdueInvoices' => (int) \frontend\models\ConsultantInvoice::find()
                ->where(['status' => \frontend\models\ConsultantInvoice::STATUS_ISSUED])->andWhere(['<', 'due_on', date('Y-m-d')])->count(),
            'pausedConsultants' => (int) \frontend\models\Consultant::find()->where(['not', ['online_booking_paused_at' => null]])->count(),
            'toApprove' => $toApprove,
            'unsigned' => $unsigned,
        ]);
    }

    /**
     * Login action.
     *
     * @return string|Response
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $this->layout = 'blank';

        $model = new AdminLoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';

        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Logout action.
     *
     * @return Response
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }
}
