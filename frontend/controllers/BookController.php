<?php

namespace frontend\controllers;

use common\components\Audit;
use common\models\AuditLog;
use frontend\components\ThrottlesByIp;
use frontend\models\BookingLink;
use frontend\models\RemoteWalkInForm;
use Yii;
use yii\web\Controller;

/**
 * The public page a booking link opens (/book/<token>): no sign-in. The patient sees the open slots
 * the link offers, picks one and books as a remote walk-in.
 */
class BookController extends Controller
{
    use ThrottlesByIp;

    public function actionIndex($token)
    {
        $this->throttle('book');

        $link = BookingLink::findByToken($token);
        if ($link === null) {
            Yii::warning('Unknown booking link token from ' . Yii::$app->request->userIP, 'booking-link');
            return $this->render('unavailable', ['reason' => 'unknown']);
        }
        if (!$link->isUsable()) {
            return $this->render('unavailable', ['reason' => $link->getStatus(), 'link' => $link]);
        }

        // Not signed in: logged as the booking link, named after its recipient
        Audit::actAs(AuditLog::ACTOR_BOOKING_LINK, null, $link->recipient_name . " (link #{$link->id})");
        $form = new RemoteWalkInForm($link);
        if ($this->request->isPost && $form->load($this->request->post()) && $form->book()) {
            return $this->render('booked', ['form' => $form, 'link' => $link]);
        }

        return $this->render('index', ['link' => $link, 'form' => $form]);
    }
}
