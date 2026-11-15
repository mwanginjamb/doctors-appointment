<?php

namespace frontend\controllers;

use common\components\Mpesa;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Where Safaricom answers our M-Pesa requests (T11): POST /mpesa/stk/<token>,
 * /mpesa/b2c-result/<token> and /mpesa/b2c-timeout/<token>.
 *
 * Daraja signs nothing, so the token in the address (MPESA_CALLBACK_TOKEN) is checked first; a
 * wrong one gets a plain 404, as if the address did not exist. {@see Mpesa} does the rest: it
 * settles each transaction once and confirms a reported STK success with a status query.
 * Safaricom is always told the callback was accepted, so it does not keep retrying one we have
 * recorded or cannot use.
 */
class MpesaController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['*' => ['POST']],
            ],
        ];
    }

    public function actionStk($token)
    {
        return $this->handle($token, 'handleStkCallback');
    }

    public function actionB2cResult($token)
    {
        return $this->handle($token, 'handleB2cResult');
    }

    public function actionB2cTimeout($token)
    {
        return $this->handle($token, 'handleB2cTimeout');
    }

    private function handle($token, $method)
    {
        /** @var Mpesa $mpesa */
        $mpesa = Yii::$app->mpesa;
        if (!$mpesa->isCallbackToken($token)) {
            throw new NotFoundHttpException();
        }

        $payload = json_decode(Yii::$app->request->getRawBody(), true);
        if (is_array($payload)) {
            try {
                $mpesa->$method($payload);
            } catch (\Throwable $e) {
                // Recorded for us to look into; mpesa/reconcile settles STK payments by query
                Yii::error("M-Pesa callback $method failed: " . $e->getMessage(), __METHOD__);
            }
        } else {
            Yii::warning('M-Pesa callback with no JSON body', __METHOD__);
        }

        Yii::$app->response->format = Response::FORMAT_JSON;

        return ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];
    }
}
