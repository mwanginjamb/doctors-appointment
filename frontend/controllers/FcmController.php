<?php
// frontend/controllers/FcmController.php

namespace frontend\controllers;

use Yii;
use yii\base\Response;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;

class FcmController extends Controller
{

    public function beforeAction($action)
    {
        if ($action->id == 'csrf-token') {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'get-config' => ['GET'],
                    'register-token' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Get minimal Firebase config (only what's absolutely necessary for client)
     */
    public function actionGetConfig()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        // Only expose non-sensitive config needed for initialization
        $config = Yii::$app->params['firebase']['public'] ?? [];

        return [
            'success' => true,
            'config' => [
                'apiKey' => $config['apiKey'] ?? '',
                'authDomain' => $config['authDomain'] ?? '',
                'projectId' => $config['projectId'] ?? '',
                'messagingSenderId' => $config['messagingSenderId'] ?? '',
                'appId' => $config['appId'] ?? '',
            ]
        ];
    }

    /**
     * Get VAPID key for token generation
     */
    public function actionGetVapidKey()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $vapidKey = Yii::$app->params['firebase']['vapidKey'] ?? '';

        return [
            'success' => true,
            'vapidKey' => $vapidKey
        ];
    }

    // Generate a CSRF token
    public function actionCsrfToken()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        return [
            'param' => Yii::$app->request->csrfParam,
            'token' => Yii::$app->request->getCsrfToken()
        ];
    }
}