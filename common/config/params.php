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
    // F4 waiting list: patients wait for a time with a consultant; when one frees up, everyone
    // waiting for it gets their link and the first to book gets it. WAITLIST=0 switches it off.
    'waitlist.enabled' => (bool) env('WAITLIST', true),
    'waitlist.maxDays' => 30,               // longest window a patient can wait for
    'waitlist.minLeadMinutes' => 120,       // only announce times at least this far ahead
    'waitlist.maxNoticesPerDay' => 3,       // per entry
    'waitlist.quietFrom' => 21,             // no notices from 21:00 ...
    'waitlist.quietUntil' => 7,             // ... until 07:00 (equal: no quiet hours)
    'waitlist.sameDayLeadMinutes' => 15,    // "earlier" for an appointment today (walk-ins at the clinic)
    'waitlist.maxEntriesPerPatient' => 5,
    // Audit log entries older than this are removed by `php yii audit/purge` (daily timer).
    // 7 years, a common period for health records.
    'audit.retentionYears' => (int) env('AUDIT_RETENTION_YEARS', 7),
    // F12 retention rules, applied daily by `php yii retention/run`: a patient account with no
    // sign-in and no appointment for this long is anonymised; symptom notes are cleared this long
    // after the visit; email sign-ups never verified are removed after this many days.
    'retention.inactiveYears' => (int) env('RETENTION_INACTIVE_YEARS', 3),
    'retention.symptomMonths' => (int) env('RETENTION_SYMPTOM_MONTHS', 12),
    'retention.unverifiedDays' => (int) env('RETENTION_UNVERIFIED_DAYS', 30),
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
    // SMS_PROVIDER: africastalking or twilio; empty means no SMS (see common\components\SmsClient)
    'sms' => [
        'provider' => env('SMS_PROVIDER', ''),
        'africastalking' => [
            'username' => env('AT_USERNAME', ''), // "sandbox" uses Africa's Talking's test API
            'api_key' => env('AT_API_KEY', ''),
            'from' => env('AT_SENDER_ID', ''), // optional registered sender ID
        ],
        'twilio' => [
            'sid' => env('TWILIO_SID', ''),
            'token' => env('TWILIO_TOKEN', ''),
            'from' => env('TWILIO_FROM', ''),
        ],
    ],
    // Google One Tap on the login and signup pages, when Google sign-in is configured (GOOGLE_ONE_TAP=0 hides it)
    'googleOneTap' => (bool) env('GOOGLE_ONE_TAP', true),
    // Patients sign in with their phone and a one-time code (see frontend\services\PhoneSignIn)
    'phoneSignIn' => [
        'enabled' => (bool) env('PHONE_SIGN_IN', true),
        'codeTtlMinutes' => (int) env('LOGIN_CODE_TTL_MINUTES', 10),
        'maxAttempts' => 5,
        'perPhonePer15Minutes' => (int) env('LOGIN_CODE_PER_PHONE_15MIN', 3),
        'perPhonePerDay' => (int) env('LOGIN_CODE_PER_PHONE_DAY', 10),
        'perIpPerHour' => (int) env('LOGIN_CODE_PER_IP_HOUR', 10),
        'resendAfterSeconds' => 60,
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
    // M-Pesa through Daraja (T11), read by common\components\Mpesa. Every account setting is in .env;
    // MPESA_ENV=sandbox until go-live. See docs/FEATURES.md, "T11 M-Pesa".
    'mpesa' => [
        'env' => env('MPESA_ENV', 'sandbox'),
        'consumerKey' => env('MPESA_CONSUMER_KEY', ''),
        'consumerSecret' => env('MPESA_CONSUMER_SECRET', ''),
        'shortcode' => env('MPESA_SHORTCODE', ''),
        'passkey' => env('MPESA_PASSKEY', ''),
        // CustomerPayBillOnline for a paybill, CustomerBuyGoodsOnline for a till
        'transactionType' => env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        // The public https:// address Safaricom calls back; empty = APP_PUBLIC_URL
        'callbackUrl' => env('MPESA_CALLBACK_URL', ''),
        'callbackToken' => env('MPESA_CALLBACK_TOKEN', ''),
        'b2cShortcode' => env('MPESA_B2C_SHORTCODE', ''),
        'initiatorName' => env('MPESA_INITIATOR_NAME', ''),
        'securityCredential' => env('MPESA_SECURITY_CREDENTIAL', ''),
        // Only where PHP has no CA bundle of its own (some Windows set-ups)
        'caFile' => env('MPESA_CA_FILE', ''),
    ],
    // F22 commitment fee (docs/business-case.md). Off until live M-Pesa credentials are in .env:
    // with it off, no booking is charged or absorbed, whatever the pilot day.
    'commitmentFee' => [
        // "1", "true", "on" or "yes"; anything else is off
        'enabled' => filter_var(env('COMMITMENT_FEE_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
        'amount' => (int) env('COMMITMENT_FEE', 250),
        // Minutes an unpaid booking holds its slot while the patient answers the M-Pesa prompt
        'holdMinutes' => (int) env('COMMITMENT_FEE_HOLD_MINUTES', 10),
        // F24: earned by the front desk for each completed Plan A booking they made ahead
        'frontDeskIncentive' => (int) env('FRONTDESK_INCENTIVE', 50),
    ],
    // F23/F24: send refunds and front-desk payouts by M-Pesa B2C. Off: platform admins pay by hand
    // and record the M-Pesa reference. Turn on once live B2C credentials are in .env.
    'payouts.b2cEnabled' => filter_var(env('MPESA_B2C_PAYOUTS_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
    // F25 consultants' monthly invoices (agreement v1.2 §3–§5). Off: no charge is recorded and no
    // invoice is issued.
    'invoices' => [
        'enabled' => filter_var(env('INVOICES_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
        // Monthly charge per active consultant on standard terms, by plan
        'planMonthly' => ['A' => (int) env('PLAN_A_MONTHLY', 1000), 'B' => (int) env('PLAN_B_MONTHLY', 1500)],
        // Per booking the consultant or their front desk cancels, from day 91, where the facility signed v1.2
        'cancellationCharge' => (int) env('CANCELLATION_PENALTY', 100),
        // An invoice is due this many days after it is issued
        'dueDays' => (int) env('INVOICE_DUE_DAYS', 14),
        // Part 2: reminders on the due date and this many days later
        'reminderGapDays' => (int) env('INVOICE_REMINDER_GAP_DAYS', 7),
        // Part 2: this many days past due, patients cannot book the consultant online until it is
        // paid (staff bookings and booking links go on); never for a pilot statement
        'pauseAfterDays' => (int) env('INVOICE_PAUSE_DAYS', 14),
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
