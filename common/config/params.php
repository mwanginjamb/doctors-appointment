<?php
return [
    'adminEmail' => env('SMTP_USERNAME'),
    'supportEmail' => env('SMTP_USERNAME'),
    'senderEmail' => env('SMTP_USERNAME'),
    'senderName' => 'Appointment Mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,
    // Booking window, enforced by BookingService (frontend/services). Consultants booking for a patient skip the notice.
    'booking.minNoticeMinutes' => 60,
    'booking.maxDaysAhead' => 90,
    // Session length for consultants who have not chosen one (minutes). DURATION in .env is in seconds.
    'booking.defaultSessionMinutes' => (int) round(env('DURATION', 1800) / 60),
    // Lengths a consultant can choose on the Availability page (minutes)
    'booking.sessionLengths' => [10, 15, 20, 30, 45, 60, 90, 120],
    // Booking links (remote walk-ins): minutes a link stays valid after it is created, and the
    // longest date range it may offer
    'bookingLink.ttlMinutes' => (int) env('BOOKING_LINK_TTL_MINUTES', 20),
    'bookingLink.maxDays' => 5,
    // Public address used in links sent to patients, e.g. https://appointments.example.com
    // (empty: the address of the request that creates the link)
    'bookingLink.baseUrl' => env('BOOKING_LINK_BASE_URL', ''),
    // Public address of the site for links built outside a web request (reminders sent by the queue
    // worker). Falls back to BOOKING_LINK_BASE_URL.
    'app.publicUrl' => env('APP_PUBLIC_URL', env('BOOKING_LINK_BASE_URL', '')),
    // Patient self-service links in reminders and "moved" notices: changes allowed until this many
    // hours before the appointment; reschedule choices cover this many days. Set SELF_SERVICE_KEY to
    // a long random string (it signs the links).
    'selfService.cutoffHours' => (int) env('SELF_SERVICE_CUTOFF_HOURS', 24),
    'selfService.rescheduleDays' => 14,
    'selfService.key' => env('SELF_SERVICE_KEY', ''),
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
            'notification_type' => 'whatsapp',
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
    // WhatsApp Configuration
    'whatsapp' => [
        'endpoint' => env('WAHA_ENDPOINT'),
        'api_key' => env('WAHA_API_KEY'), // Optional - for commercial WAHA plans
        'sender_number' => env('WAHA_SENDER_NUMBER'), // Optional - get it from user profiles
        'session' => env('WAHA_SESSION'),
    ],
    // Optional address that receives a blind copy of every reminder email (contains patient details)
    'notificationBcc' => env('MAIL_BCC'),
    // Push Notification Configuration (FCM HTTP v1)
    'push' => [
        'fcm' => [
            'project_id' => env('FIREBASE_PROJ'),
            // Path to the Firebase service-account JSON key (Project settings > Service accounts)
            'credentials_file' => env('FCM_CREDENTIALS_FILE'),
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
