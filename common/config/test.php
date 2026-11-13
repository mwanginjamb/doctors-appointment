<?php
return [
    'id' => 'app-common-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        // Fixed test keys, so sign-in tests do not depend on .env (nothing is sent to the providers)
        'authClientCollection' => [
            'clients' => [
                'google' => [
                    'class' => 'yii\authclient\clients\Google',
                    'clientId' => 'test-client.apps.googleusercontent.com',
                    'clientSecret' => 'test-secret',
                ],
                'linkedin' => [
                    'class' => \common\authclient\LinkedIn::class,
                    'clientId' => 'test-linkedin',
                    'clientSecret' => 'test-secret',
                ],
            ],
        ],
        // Sign-in codes are kept in phoneMessenger->outbox, never sent
        'phoneMessenger' => [
            'class' => \common\components\PhoneMessenger::class,
            'fake' => true,
        ],
        'user' => [
            'class' => \yii\web\User::class,
            'identityClass' => 'common\models\User',
            // Unit tests log in without a PHP session: starting one fails once the test runner has
            // printed output ("Session cookie parameters cannot be changed after headers have already been sent")
            'enableSession' => false,
        ],
    ],
];
