<?php
$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id' => 'app-frontend',
    'name' => env('APP_NAME'),
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'frontend\controllers',
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-frontend',
            'enableCsrfValidation' => true,
            'enableCookieValidation' => true,
        ],
        'user' => [
            'identityClass' => 'common\models\User',
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-frontend', 'httpOnly' => true],
        ],
        'session' => [
            // this is the name of the session cookie used for login on the frontend
            'name' => 'advanced-frontend',
            'class' => 'yii\web\Session',
            'timeout' => 3600, // 60 min
            'useCookies' => true,
            'cookieParams' => [
                'httponly' => true,
                'lifetime' => 3600, // one hour
                'samesite' => 'Lax', // OAuth callbacks are top-level redirects, which Lax allows
                'secure' => YII_ENV === 'dev' ? false : true,     // Ensure secure (HTTPS) transmission only on prod
            ],
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                ],
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['info', 'error', 'warning'],
                    'categories' => ['jobs', 'notifications'],
                    'logFile' => '@runtime/logs/jobs.log',
                    'logVars' => []
                ],
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['info'],
                    'categories' => ['linkedin'],
                    'logFile' => '@runtime/logs/linkedin.log',
                    'logVars' => []
                ],
                // Signup funnel: validation / persist / mail / exception outcomes.
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['info', 'error', 'warning'],
                    'categories' => ['signup'],
                    'logFile' => '@runtime/logs/signup.log',
                    'logVars' => [],
                    'prefix' => [\common\components\RequestContext::class, 'logPrefix'],
                    'maxFileSize' => 10240,
                    'maxLogFiles' => 10,
                ],
                // Every email send (success + classified failure with SMTP transcript)
                // plus Symfony transport events.
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['info', 'error', 'warning'],
                    'categories' => ['mail', 'yii\symfonymailer\*'],
                    'logFile' => '@runtime/logs/mail.log',
                    'logVars' => [],
                    'prefix' => [\common\components\RequestContext::class, 'logPrefix'],
                    'maxFileSize' => 10240,
                    'maxLogFiles' => 10,
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],

        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                // Booking links sent to patients: /book/<token>
                'book/<token:[A-Za-z0-9_-]+>' => 'book/index',
            ],
        ],

    ],
    'params' => $params,
];
