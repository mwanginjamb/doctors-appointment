<?php
return [
    'adminEmail' => env('SMTP_USERNAME'),
    'supportEmail' => env('SMTP_USERNAME'),
    'senderEmail' => env('SMTP_USERNAME'),
    'senderName' => 'Appointment Mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,
];
