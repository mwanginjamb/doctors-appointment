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
    // Currency of consultation fees, set per consultant and facility (F8)
    'currency' => env('CURRENCY', 'KES'),
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
    // Audit log entries older than this are removed by `php yii audit/purge` (daily timer).
    // 7 years, a common period for health records.
    'audit.retentionYears' => (int) env('AUDIT_RETENTION_YEARS', 7),
    // Privacy notice (F11, Kenya Data Protection Act). Change privacy.version whenever the notice
    // text changes: signed-in patients are then asked to accept it again. The controller's details
    // come from .env so each deployment states its own; missing ones show as "to be provided".
    'privacy.version' => '2026-11.2', // F8 phase 2: names the facility and its front desk
    // F11 can be switched off with CONSENT_ENABLED=false, e.g. outside Kenya or for demos. Required
    // for Kenyan deployments under the Data Protection Act; admins see a warning while it is off.
    'consent.enabled' => filter_var(env('CONSENT_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
    // T2 monitoring. Errors go to a Sentry-compatible server (self-hosted GlitchTip, see
    // deploy/glitchtip); empty DSN = off. Personal data is scrubbed before anything is sent.
    'monitoring.dsn' => env('SENTRY_DSN', ''),
    'monitoring.environment' => env('SENTRY_ENVIRONMENT', defined('YII_ENV') ? YII_ENV : 'prod'),
    'monitoring.release' => env('APP_RELEASE', ''),
    // Health checks (php yii health/check, every 5 minutes): alerts by email to ALERT_EMAILS
    // (comma-separated); each run pings HEALTHCHECK_PING_URL (e.g. healthchecks.io) when set.
    'health.alertEmails' => env('ALERT_EMAILS', ''),
    'health.pingUrl' => env('HEALTHCHECK_PING_URL', ''),
    'health.queueMaxWaitMinutes' => (int) env('HEALTH_QUEUE_MAX_WAIT_MINUTES', 5),
    'health.reminderMaxLateMinutes' => (int) env('HEALTH_REMINDER_MAX_LATE_MINUTES', 10),
    'health.failuresPerHour' => (int) env('HEALTH_FAILURES_PER_HOUR', 3),
    'health.realertHours' => (int) env('HEALTH_REALERT_HOURS', 6),
    'privacy.controllerName' => env('PRIVACY_CONTROLLER_NAME', ''),
    'privacy.controllerAddress' => env('PRIVACY_CONTROLLER_ADDRESS', ''),
    'privacy.contactEmail' => env('PRIVACY_CONTACT_EMAIL', ''),
    'privacy.contactPhone' => env('PRIVACY_CONTACT_PHONE', ''),
    'privacy.dpoContact' => env('PRIVACY_DPO_CONTACT', ''),
    'privacy.odpcRegistration' => env('ODPC_REGISTRATION_NUMBER', ''),
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
