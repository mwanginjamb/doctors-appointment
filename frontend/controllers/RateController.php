<?php

namespace frontend\controllers;

use frontend\services\Ratings;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F30: the patient rates the consultant for one attended visit, from the signed link in the email
 * or WhatsApp sent after it (/rate/<appointment id>/<signature>); no sign-in. 1–5 stars and an
 * optional private comment; can be changed while the link is open (30 days).
 */
class RateController extends Controller
{
    public function actionIndex($id, $sig)
    {
        $appointment = Ratings::verify($id, $sig);
        if ($appointment === null) {
            throw new NotFoundHttpException(Yii::t('app', 'This link is not valid.'));
        }
        $rating = Ratings::forAppointment($appointment);
        $open = Ratings::isOpen($appointment);

        if (Yii::$app->request->isPost && $open) {
            $rating = Ratings::rate($appointment, Yii::$app->request->post('stars'), (string) Yii::$app->request->post('comment', ''));
            if (!$rating->hasErrors()) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Thank you for rating your visit.'));

                return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
            }
        }

        return $this->render('index', ['appointment' => $appointment, 'rating' => $rating, 'open' => $open]);
    }
}
