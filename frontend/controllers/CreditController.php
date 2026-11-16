<?php

namespace frontend\controllers;

use frontend\models\PatientCredit;
use frontend\services\FeeLifecycle;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F23: a patient's rebooking credit, at /credit/<id>/<signature> (sent in the message that told
 * them about it). Shows what it is and until when, and, when the clinic cancelled, lets them ask
 * for the money back by M-Pesa instead. No sign-in.
 */
class CreditController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['refund' => ['POST']]],
        ];
    }

    public function actionView($id, $sig)
    {
        $credit = $this->findCredit($id, $sig);
        $this->layout = Yii::$app->user->isGuest ? 'guest' : 'main';

        return $this->render('view', ['credit' => $credit, 'sig' => $sig]);
    }

    public function actionRefund($id, $sig)
    {
        $credit = $this->findCredit($id, $sig);
        if (FeeLifecycle::requestRefund($credit, $this->request->post('phone'))) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Your refund request is received. You will get the Ksh {amount} by M-Pesa once it is approved.', ['amount' => number_format($credit->amount)]));
        } else {
            $errors = $credit->getFirstErrors();
            Yii::$app->session->setFlash('error', $errors ? reset($errors) : Yii::t('app', 'This credit cannot be refunded.'));
        }

        return $this->redirect(['view', 'id' => $credit->id, 'sig' => $sig]);
    }

    private function findCredit($id, $sig)
    {
        $credit = PatientCredit::findOne((int) $id);
        if ($credit === null || !hash_equals($credit->signature(), (string) $sig)) {
            throw new NotFoundHttpException(Yii::t('app', 'This link is not valid.'));
        }

        return $credit;
    }
}
