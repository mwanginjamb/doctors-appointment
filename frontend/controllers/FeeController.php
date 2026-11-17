<?php

namespace frontend\controllers;

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use frontend\services\CommitmentFees;
use frontend\services\FeeRules;
use frontend\services\SelfServiceLink;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * F22: the "check your phone" page of a booking awaiting its commitment fee, at
 * /pay/<appointment id>/<signature>: it follows the payment, sends the M-Pesa prompt again while
 * the slot is held, and cancels the booking. Signed like the self-service links, so patients
 * booking by link or from the waiting list (not signed in) use it too, as do staff.
 */
class FeeController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['resend' => ['POST'], 'cancel' => ['POST']],
            ],
        ];
    }

    public function actionPay($id, $sig)
    {
        $appointment = $this->findAppointment($id, $sig);
        $this->layout = Yii::$app->user->isGuest ? 'guest' : 'main';
        Yii::$app->response->format = Response::FORMAT_HTML;

        return $this->render('pay', [
            'appointment' => $appointment,
            'fee' => CommitmentFee::forAppointment($appointment->id),
            'state' => CommitmentFees::statusOf($appointment),
            'sig' => $sig,
            'amount' => FeeRules::amount(),
        ]);
    }

    /** JSON for the page to follow the payment. */
    public function actionStatus($id, $sig)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        return CommitmentFees::statusOf($this->findAppointment($id, $sig));
    }

    public function actionResend($id, $sig)
    {
        $appointment = $this->findAppointment($id, $sig);
        if (CommitmentFees::resend($appointment)) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'The M-Pesa prompt was sent again. Check the phone.'));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'The prompt could not be sent now: wait a minute after the last one, or the time is no longer held.'));
        }

        return $this->redirect(['pay', 'id' => $appointment->id, 'sig' => $sig]);
    }

    public function actionCancel($id, $sig)
    {
        $appointment = $this->findAppointment($id, $sig);
        // Staff cancel a booking the patient did not pay for at the patient's request
        if ($appointment->isAwaitingPayment() && $appointment->cancel('Cancelled before the commitment fee was paid.', Appointments::REASON_PATIENT_REQUEST)) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'The booking is cancelled and the time released. Nothing was charged.'));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'This booking can no longer be cancelled here.'));
        }

        return $this->redirect(['pay', 'id' => $appointment->id, 'sig' => $sig]);
    }

    private function findAppointment($id, $sig)
    {
        $appointment = Appointments::findOne((int) $id);
        if ($appointment === null || !hash_equals(SelfServiceLink::signature($appointment), (string) $sig)
            || CommitmentFee::forAppointment($appointment->id) === null) {
            throw new NotFoundHttpException(Yii::t('app', 'This link is not valid.'));
        }

        return $appointment;
    }
}
