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
            // Logs every send and keeps a classified $lastError (see class docs).
            // If a *-local.php config redefines 'class', it must use this class too.
            'class' => \common\components\ObservableMailer::class,
            'viewPath' => '@common/mail',
            'useFileTransport' => false,
            'transport' => [
                // The DSN is the only transport setting the mailer reads when it is
                // present. Credentials MUST be URL-encoded: an "@", ":", "/", "#" or
                // "?" in the password otherwise corrupts the DSN and auth fails.
                //   smtp://  -> plain connect, upgraded with STARTTLS (port 587/25)
                //   smtps:// -> implicit TLS (port 465)
                'transport' => [
                    'dsn' => 'smtp://' . env('SMTP_USERNAME') . ':' . env('SMTP_PASSWORD') . '@' . env('SMTP_HOST') . ':' . env('SMTP_PORT', 587),
                 ],
                'enableMailerLogging' => true,
            ],
        ],

    ],
];
