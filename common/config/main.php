<?php
return [
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'bootstrap' => [
        'queue',
    ],
    'components' => [
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

    ],
];
