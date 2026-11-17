<?php

namespace backend\controllers;

use frontend\models\PatientCredit;
use frontend\services\FeeLifecycle;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F23: patients' requests to have a rebooking credit refunded by M-Pesa. A platform admin
 * approves (sent by B2C when MPESA_B2C_PAYOUTS_ENABLED=1, else paid by hand), records one paid by
 * hand with its M-Pesa reference, or declines (the credit stays usable).
 */
class RefundController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['approve' => ['POST'], 'record' => ['POST'], 'reject' => ['POST']],
            ],
        ];
    }

    public function actionIndex($status = PatientCredit::STATUS_REFUND_REQUESTED)
    {
        $query = PatientCredit::find()->with(['patient', 'sourceFee.appointment.consultant'])
            ->where(['refundable' => 1])
            ->orderBy(['refund_requested_at' => SORT_ASC, 'id' => SORT_ASC]);
        if ($status !== '') {
            $query->andWhere(['status' => $status]);
        } else {
            $query->andWhere(['not', ['refund_requested_at' => null]]);
        }

        return $this->render('index', [
            'dataProvider' => new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 30], 'sort' => false]),
            'status' => $status,
            'b2c' => FeeLifecycle::b2cEnabled(),
        ]);
    }

    public function actionApprove($id)
    {
        $credit = $this->findCredit($id);
        if (FeeLifecycle::approveRefund($credit, Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', FeeLifecycle::b2cEnabled()
                ? "Refund #{$credit->id} sent by M-Pesa; it shows as refunded once M-Pesa confirms."
                : "Refund #{$credit->id} approved. Pay Ksh {$credit->amount} to {$credit->refund_phone} by M-Pesa, then record the reference.");
        } else {
            Yii::$app->session->setFlash('error', "Refund #{$credit->id} could not be approved" . ($credit->note ? ": {$credit->note}" : '.'));
        }

        return $this->redirect(['index']);
    }

    public function actionRecord($id)
    {
        $credit = $this->findCredit($id);
        if (FeeLifecycle::markRefunded($credit, $this->request->post('reference'), Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "Refund #{$credit->id} recorded as paid ({$credit->refund_reference}).");
        } else {
            Yii::$app->session->setFlash('error', 'Enter the M-Pesa reference (letters and numbers, e.g. UKG8420XHO).');
        }

        return $this->redirect(['index']);
    }

    public function actionReject($id)
    {
        $credit = $this->findCredit($id);
        $reason = trim((string) $this->request->post('reason'));
        if ($reason === '') {
            Yii::$app->session->setFlash('error', 'Say why the refund is declined.');
        } elseif (FeeLifecycle::rejectRefund($credit, Yii::$app->user->id, $reason)) {
            Yii::$app->session->setFlash('success', "Refund #{$credit->id} declined; the credit stays usable until it expires.");
        }

        return $this->redirect(['index']);
    }

    private function findCredit($id)
    {
        $credit = PatientCredit::findOne((int) $id);
        if ($credit === null) {
            throw new NotFoundHttpException('No such credit.');
        }

        return $credit;
    }
}
