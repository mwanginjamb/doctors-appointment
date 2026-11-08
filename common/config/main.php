<?php
return [
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    // Appointment dates/times are stored as local wall-clock values, so PHP and MySQL must agree on the zone
    'timeZone' => env('APP_TIMEZONE', 'Africa/Nairobi'),
    'bootstrap' => [
        'queue',
    ],
    'components' => [
        'db' => [
            'on afterOpen' => function ($event) {
                $event->sender->createCommand('SET time_zone = :tz', [':tz' => (new \DateTime())->format('P')])->execute();
            },
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'utility' => [
            'class' => \common\Library\UtilityComponent::class
        ],
        'authClientCollection' => [
            'class' => 'yii\authclient\Collection',
            'clients' => [
                'google' => [
                    'class' => 'yii\authclient\clients\Google',
                    'clientId' => env('GOOGLE_CLIENT_ID'),
                    'clientSecret' => env('GOOGLE_CLIENT_SECRET'),
                ],
                'linkedin' => [
                    'class' => 'yii\authclient\clients\LinkedIn',
                    'clientId' => env('LINKEDIN_CLIENT_ID'),
                    'clientSecret' => env('LINKEDIN_CLIENT_SECRET'),
                ],
            ],
        ],
        'queue' => [
            'class' => \yii\queue\db\Queue::class,
            'db' => 'db', // DB connection component or its config 
            'tableName' => '{{%queue}}', // Table name
            'channel' => 'default', // Queue channel key
            'ttr' => 300,
            'attempts' => 3,
            'mutex' => \yii\mutex\MysqlMutex::class, // Mutex used to sync queries
        ],
        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@common/mail',
            'useFileTransport' => false,
            'transport' => [
                'scheme' => 'smtp',
                'host' => env('SMTP_HOST'),
                'username' => env('SMTP_USERNAME'),
                'password' => env('SMTP_PASSWORD'),
                'port' => env('SMTP_PORT'),
                //'dsn' => 'smtp://' . env('SMTP_USERNAME') . ':' . env('SMTP_PASSWORD') . '@' . env('SMTP_HOST') . ':' . env('SMTP_PORT'),
                'enableMailerLogging' => true
            ],
        ],

    ],
];
