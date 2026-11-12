<?php

namespace frontend\controllers;

use frontend\models\CalendarFeed;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * A consultant's calendar feed (F9): /calendar/<secret>.ics, read without signing in by the
 * consultant's calendar (Google, Apple, Outlook). The secret in the address is the only key.
 */
class CalendarFeedController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIcs($token)
    {
        $consultant = CalendarFeed::findConsultant($token);
        if ($consultant === null) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->headers->set('Content-Disposition', 'inline; filename="appointments.ics"');
        $response->headers->set('Cache-Control', 'private, max-age=900');
        // Calendar providers follow no links in it; keep it out of search engines all the same
        $response->headers->set('X-Robots-Tag', 'noindex');

        return (new CalendarFeed(['consultant' => $consultant]))->render();
    }
}
