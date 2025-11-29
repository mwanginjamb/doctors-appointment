<?php
return [
    'adminEmail' => env('SMTP_USERNAME'),
    'supportEmail' => env('SMTP_USERNAME'),
    'senderEmail' => env('SMTP_USERNAME'),
    'senderName' => 'Appointment Mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,
    // Default notification schedules to create for new users
    'defaultNotificationSchedules' => [
        [
            'notification_type' => 'email',
            'minutes_before' => 1440, // 24 hours
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        [
            'notification_type' => 'email',
            'minutes_before' => 120, // 2 hours
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        [
            'notification_type' => 'email',
            'minutes_before' => 30, // 30 minutes
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        [
            'notification_type' => 'push',
            'minutes_before' => 1440, // 24 hour
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        [
            'notification_type' => 'push',
            'minutes_before' => 120, // 2 hours
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        [
            'notification_type' => 'whatapp',
            'minutes_before' => 120, // 2 hours
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        [
            'notification_type' => 'whatsapp',
            'minutes_before' => 60, // 1 hour
            'is_active' => 1,
            'notification_method' => 'both',
        ],
        // Uncomment if you have SMS configured
        // [
        //     'notification_type' => 'sms',
        //     'minutes_before' => 60, // 1 hour
        //     'is_active' => 0, // Disabled by default
        //     'notification_method' => 'patient',
        // ],
    ],
    // Whether to auto-create default schedules for new appointments
    'autoCreateDefaultSchedules' => true,
    // Whether to create schedules for both patient and consultant, or just patient
    'createSchedulesFor' => 'both', // 'patient', 'consultant', 'both'
    // SMS Configuration
    'sms' => [
        'provider' => 'twilio', // or 'aws'
        'twilio' => [
            'sid' => 'your_twilio_account_sid',
            'token' => 'your_twilio_auth_token',
            'from' => '+1234567890',
        ],
    ],
    // Push Notification Configuration
    'push' => [
        'fcm' => [
            'server_key' => env('FCM_KEY'),
        ],
    ],
    // Firebase Configurations
    'firebase' => [
        'public' => [
            'apiKey' => env('FIREBASE_API_KEY'),
            'authDomain' => env('FIREBASE_DOMAIN'),
            'projectId' => env('FIREBASE_PROJ'),
            'storageBucket' => env('FIREBASE_STORE'),
            'messagingSenderId' => env('FIREBASE_SENDERID'),
            'appId' => env('FIREBASE_APPID'),
            'measurementId' => env('FIREBASE_MEASUREID'),
        ],
        'vapidKey' => env('FIREBASE_VAPID_KEY'),
    ],
];
