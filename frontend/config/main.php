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
    // F11: signed-in patients must agree to the current privacy notice before using the app
    'as consentGate' => ['class' => 'frontend\components\ConsentGate'],
    'components' => [
        // ?v=<file time> on our scripts and styles, so browsers fetch them again after a deploy
        'assetManager' => [
            'appendTimestamp' => true,
        ],
        'request' => [
            'csrfParam' => '_csrf-frontend',
            'enableCsrfValidation' => true,
            'enableCookieValidation' => true,
        ],
        'user' => [
            'identityClass' => 'common\models\User',
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-frontend', 'httpOnly' => true],
            // Audit log: every sign-in (password, Google/LinkedIn, email verification) and sign-out
            'on afterLogin' => function ($event) {
                \common\components\Audit::onLogin($event);
                // Signing in with a password or Google/LinkedIn while a phone code is proven links the number
                \frontend\services\PhoneSignIn::onAfterLogin($event);
            },
            'on afterLogout' => ['common\components\Audit', 'onLogout'],
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
                // T2: errors to the self-hosted error tracker (GlitchTip); off without SENTRY_DSN
                ['class' => \common\log\ErrorTrackerTarget::class],
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
                // Patient self-service links: /manage/<appointment id>/<signature>[/cancel|/reschedule]
                'manage/<id:\d+>/<sig:[a-f0-9]{32}>' => 'manage/index',
                'manage/<id:\d+>/<sig:[a-f0-9]{32}>/<action:cancel|reschedule|confirm|waitlist>' => 'manage/<action>',
                // F4 waiting-list links: /waitlist/<entry id>/<signature>[/book|/leave]
                'waitlist/<id:\d+>/<sig:[a-f0-9]{32}>' => 'waitlist/offer',
                'waitlist/<id:\d+>/<sig:[a-f0-9]{32}>/<action:book|leave>' => 'waitlist/<action>',
                // F9: a consultant's calendar feed, read by Google, Apple or Outlook calendar
                'calendar/<token:[A-Za-z0-9_-]{43}>.ics' => 'calendar-feed/ics',
                // M-Pesa callbacks (T11): Safaricom posts here; the token is MPESA_CALLBACK_TOKEN
                'POST mpesa/<action:stk|b2c-result|b2c-timeout>/<token:[A-Za-z0-9_-]{20,128}>' => 'mpesa/<action>',
                // Standard terms after the pilot: /terms/<facility id>, confirmed by POST to /terms/<facility id>/confirm
                'terms/<facility:\d+>' => 'terms/view',
                'POST terms/<facility:\d+>/<action:confirm|approve>' => 'terms/<action>',
                // F22: paying the commitment fee of a held booking: /pay/<appointment id>/<signature>
                'pay/<id:\d+>/<sig:[a-f0-9]{32}>' => 'fee/pay',
                'pay/<id:\d+>/<sig:[a-f0-9]{32}>/<action:status|resend|cancel>' => 'fee/<action>',
                // F23: a patient's rebooking credit: /credit/<id>/<signature>[/refund]
                'credit/<id:\d+>/<sig:[a-f0-9]{32}>' => 'credit/view',
                'POST credit/<id:\d+>/<sig:[a-f0-9]{32}>/refund' => 'credit/refund',
                // F25: a consultant's monthly invoice: /invoice/<id>/<signature>
                'invoice/<id:\d+>/<sig:[a-f0-9]{32}>' => 'invoice/view',
                'invoice/<id:\d+>/<sig:[a-f0-9]{32}>/<action:pay|status>' => 'invoice/<action>',
                // F29: a consultant's public profile (the business card's QR code): /dr/<id>-<name>
                'dr/<id:\d+>-<slug:[a-z0-9-]+>' => 'doctor/view',
                'dr/<id:\d+>' => 'doctor/view',
                // F14: bookings clashing with a closure, time off or holiday: /clash/<type>/<id>[/tell]
                'clash/<type:closure|time_off|holiday>/<id:\d+>' => 'clash/view',
                'POST clash/<type:closure|time_off|holiday>/<id:\d+>/tell' => 'clash/tell',
                // F29: the business card and its QR code
                'business-card' => 'business-card/index',
                'business-card/<cid:\d+>' => 'business-card/index',
                'business-card/<cid:\d+>/qr.<format:svg|png>' => 'business-card/qr',
                'business-card/<cid:\d+>/print' => 'business-card/print',
            ],
        ],

    ],
    'params' => $params,
];
