<?php

namespace frontend\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * Public uptime endpoint for an external monitor (T2, e.g. UptimeRobot): GET /health answers "ok"
 * when the site and its database work, and HTTP 503 otherwise. It shows nothing else; the detailed
 * checks are `php yii health/status` and System health on the admin dashboard.
 */
class HealthController extends Controller
{
    public function actionIndex()
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');

        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            return 'ok';
        } catch (\Throwable $e) {
            Yii::error('Health endpoint: database unavailable: ' . $e->getMessage(), __METHOD__);
            $response->statusCode = 503;
            return 'unavailable';
        }
    }
}
