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
        // Fixed M-Pesa settings, and no network: a test that needs Safaricom gives its own transport
        'mpesa' => [
            'class' => \common\components\Mpesa::class,
            'env' => 'sandbox',
            'consumerKey' => 'test-key',
            'consumerSecret' => 'test-secret',
            'shortcode' => '174379',
            'passkey' => 'test-passkey',
            'callbackUrl' => 'https://appointments.example.test',
            'callbackToken' => 'test-callback-token-0123456789',
            'b2cShortcode' => '600000',
            'initiatorName' => 'testapi',
            'securityCredential' => 'test-credential',
            'transport' => static function () {
                throw new \common\components\MpesaException('Tests do not call Safaricom.');
            },
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
