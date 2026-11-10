<?php

namespace frontend\controllers;

use frontend\models\BookingLink;
use frontend\models\RemoteWalkInForm;
use Yii;
use yii\web\Controller;
use yii\web\TooManyRequestsHttpException;

/**
 * The public page a booking link opens (/book/<token>): no sign-in. The patient sees the open slots
 * the link offers, picks one and books as a remote walk-in.
 */
class BookController extends Controller
{
    /** Requests allowed per IP address per window, against guessing tokens or flooding bookings */
    const RATE_LIMIT = 40;
    const RATE_WINDOW = 600;

    public function actionIndex($token)
    {
        $this->throttle();

        $link = BookingLink::findByToken($token);
        if ($link === null) {
            Yii::warning('Unknown booking link token from ' . Yii::$app->request->userIP, 'booking-link');
            return $this->render('unavailable', ['reason' => 'unknown']);
        }
        if (!$link->isUsable()) {
            return $this->render('unavailable', ['reason' => $link->getStatus(), 'link' => $link]);
        }

        $form = new RemoteWalkInForm($link);
        if ($this->request->isPost && $form->load($this->request->post()) && $form->book()) {
            return $this->render('booked', ['form' => $form, 'link' => $link]);
        }

        return $this->render('index', ['link' => $link, 'form' => $form]);
    }

    private function throttle()
    {
        $key = 'book-throttle:' . Yii::$app->request->userIP;
        $cache = Yii::$app->cache;
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, $count, self::RATE_WINDOW);
        if ($count > self::RATE_LIMIT) {
            throw new TooManyRequestsHttpException(Yii::t('app', 'Too many requests. Please wait a few minutes and try again.'));
        }
    }
}
