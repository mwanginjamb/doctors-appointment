<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\models\ConsultantInvoice;
use frontend\services\Invoices;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * F25: a consultant's monthly invoices. Each one is at /invoice/<id>/<signature> (the link in its
 * email and WhatsApp; no sign-in), paid from its "Pay via M-Pesa" page (/pay, the button in its emails); a signed-in
 * consultant lists theirs under My invoices.
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

        return $this->render('index', [
            'invoices' => $invoices,
            'paused' => $consultant !== null && $consultant->isOnlineBookingPaused(),
        ]);
    }

    public function actionView($id, $sig)
    {
        $invoice = $this->findInvoice($id, $sig);
        $this->layout = Yii::$app->user->isGuest ? 'guest' : 'main';

        return $this->render('view', [
            'invoice' => $invoice,
            'lines' => $invoice->lines,
            'state' => Invoices::statusOf($invoice),
            'canPay' => Invoices::canPayByPrompt($invoice),
            'paused' => $invoice->consultant !== null && $invoice->consultant->isOnlineBookingPaused(),
        ]);
    }

    /**
     * The "Pay via M-Pesa" page, the button in the invoice emails. Opening it only shows the
     * page (email scanners open links too); one tap (POST) sends the M-Pesa prompt to the
     * consultant's number, or another number given, and the page follows the payment.
     */
    public function actionPay($id, $sig)
    {
        $invoice = $this->findInvoice($id, $sig);
        if (!Invoices::canPayByPrompt($invoice)) {
            return $this->redirect($invoice->url());
        }
        if (Yii::$app->request->isPost) {
            $problem = Invoices::sendPrompt($invoice, trim((string) Yii::$app->request->post('phone', '')) ?: null);
            if ($problem === null) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'An M-Pesa prompt is on its way. Enter your M-Pesa PIN on the phone to pay.'));
            } else {
                Yii::$app->session->setFlash('error', $problem);
            }

            return $this->redirect($invoice->payUrl());
        }
        $this->layout = Yii::$app->user->isGuest ? 'guest' : 'main';

        return $this->render('pay', ['invoice' => $invoice, 'state' => Invoices::statusOf($invoice)]);
    }

    /** JSON for the page to follow the payment. */
    public function actionStatus($id, $sig)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        return Invoices::statusOf($this->findInvoice($id, $sig));
    }

    private function findInvoice($id, $sig)
    {
        $invoice = ConsultantInvoice::findOne((int) $id);
        if ($invoice === null || !hash_equals($invoice->signature(), (string) $sig)) {
            throw new NotFoundHttpException(Yii::t('app', 'This link is not valid.'));
        }

        return $invoice;
    }
}
