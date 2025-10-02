<?php

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php',
    require __DIR__ . '/../../common/config/main.php',
    require __DIR__ . '/../../common/config/main-local.php',
);

return [
    'id' => 'app-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'console\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'controllerMap' => [
        'fixture' => [
            'class' => \yii\console\controllers\FixtureController::class,
            'namespace' => 'common\fixtures',
        ],
        /* 'migrate' => [
             'class' => 'yii\console\controllers\MigrateController',
             'migrationPath' => null,
             'migrationNamespaces' => [
                 'yii\queue\db\migrations',
             ],
         ],*/
    ],
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'except' => ['email.reminder*'],
                    'maxFileSize' => 10240, // 10MB
                    'maxLogFiles' => 7,
                    'logVars' => [],
                ],
                // Email reminder specific logs
                [
                    'class' => 'yii\log\FileTarget',
                    'logFile' => '@runtime/logs/email-reminders.log',
                    'categories' => ['email.reminder*'],
                    'exportInterval' => 1,
                    'logVars' => [],
                    'prefix' => function ($message) {
                        return '[' . date('Y-m-d H:i:s') . '][' . getmypid() . ']';
                    },
                    'maxFileSize' => 50240, // 50MB - larger for detailed email logs
                    'maxLogFiles' => 14, // Keep 2 weeks
                ],
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['info', 'error', 'warning'],
                    'categories' => ['jobs', 'notifications'],
                    'logFile' => '@runtime/logs/jobs.log',
                    'logVars' => []
                ]


            ],
        ],
    ],
    'params' => $params,
];
