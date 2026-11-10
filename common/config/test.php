<?php
return [
    'id' => 'app-common-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        'user' => [
            'class' => \yii\web\User::class,
            'identityClass' => 'common\models\User',
            // Unit tests log in without a PHP session: starting one fails once the test runner has
            // printed output ("Session cookie parameters cannot be changed after headers have already been sent")
            'enableSession' => false,
        ],
    ],
];
