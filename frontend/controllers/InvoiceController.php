<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\models\ConsultantInvoice;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F25: a consultant's monthly invoices. Each one is at /invoice/<id>/<signature> (the link in its
 * email and WhatsApp; no sign-in), and a signed-in consultant lists theirs under My invoices.
 */
class InvoiceController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['index'],
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function () {
                        return Yii::$app->user->identity->isConsultantAccount();
                    },
                ]],
            ],
        ];
    }

    public function actionIndex()
    {
        $consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
        $invoices = $consultant === null ? [] : ConsultantInvoice::find()
            ->where(['consultant_id' => $consultant->id])
            ->orderBy(['period_start' => SORT_DESC, 'id' => SORT_DESC])->all();

        return $this->render('index', ['invoices' => $invoices]);
    }

    public function actionView($id, $sig)
    {
        $invoice = ConsultantInvoice::findOne((int) $id);
        if ($invoice === null || !hash_equals($invoice->signature(), (string) $sig)) {
            throw new NotFoundHttpException(Yii::t('app', 'This link is not valid.'));
        }
        $this->layout = Yii::$app->user->isGuest ? 'guest' : 'main';

        return $this->render('view', ['invoice' => $invoice, 'lines' => $invoice->lines]);
    }
}
