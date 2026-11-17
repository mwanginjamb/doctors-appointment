<?php

namespace backend\controllers;

use frontend\models\IncentivePayout;
use frontend\services\FeeLifecycle;
use frontend\services\Incentives;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F24: the weekly front-desk payouts (built every Monday by php yii incentive/payouts). A platform
 * admin sends one by M-Pesa B2C (MPESA_B2C_PAYOUTS_ENABLED=1) or pays by hand and records the
 * reference. A payout held for a missing phone is checked again once the account is fixed.
 */
class PayoutController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['send' => ['POST'], 'record' => ['POST'], 'recheck' => ['POST'], 'build' => ['POST']],
            ],
        ];
    }

    public function actionIndex($status = '')
    {
        $query = IncentivePayout::find()->with('frontDesk')->orderBy(['period_start' => SORT_DESC, 'id' => SORT_ASC]);
        if ($status !== '') {
            $query->andWhere(['status' => $status]);
        }

        return $this->render('index', [
            'dataProvider' => new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 30], 'sort' => false]),
            'status' => $status,
            'b2c' => FeeLifecycle::b2cEnabled(),
        ]);
    }

    public function actionView($id)
    {
        $payout = $this->findPayout($id);

        return $this->render('view', ['payout' => $payout, 'items' => $payout->getItems()->with('appointment.consultant')->all()]);
    }

    /** Builds last week's payouts now, as the Monday timer does (safe to repeat). */
    public function actionBuild()
    {
        $payouts = Incentives::buildWeeklyPayouts();
        Yii::$app->session->setFlash('success', count($payouts) . ' payouts built or brought up to date for last week.');

        return $this->redirect(['index']);
    }

    public function actionSend($id)
    {
        $payout = $this->findPayout($id);
        if (Incentives::send($payout, Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "Payout #{$payout->id} sent by M-Pesa; it shows as paid once M-Pesa confirms.");
        } else {
            Yii::$app->session->setFlash('error', "Payout #{$payout->id} was not sent" . ($payout->note ? ": {$payout->note}" : '.'));
        }

        return $this->redirect(['index']);
    }

    public function actionRecord($id)
    {
        $payout = $this->findPayout($id);
        if (Incentives::markPaid($payout, $this->request->post('reference'), Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "Payout #{$payout->id} recorded as paid ({$payout->reference}).");
        } else {
            Yii::$app->session->setFlash('error', 'Enter the M-Pesa reference (letters and numbers, e.g. UKG8420XHO).');
        }

        return $this->redirect(['index']);
    }

    public function actionRecheck($id)
    {
        $payout = $this->findPayout($id);
        Incentives::refreshPhone($payout);
        Yii::$app->session->setFlash($payout->status === IncentivePayout::STATUS_READY ? 'success' : 'error',
            $payout->status === IncentivePayout::STATUS_READY ? "Payout #{$payout->id} is ready: {$payout->phone}." : "Payout #{$payout->id} is still held: the account has no Kenyan phone number.");

        return $this->redirect(['index']);
    }

    private function findPayout($id)
    {
        $payout = IncentivePayout::findOne((int) $id);
        if ($payout === null) {
            throw new NotFoundHttpException('No such payout.');
        }

        return $payout;
    }
}
