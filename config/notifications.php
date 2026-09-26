<?php

/*
|--------------------------------------------------------------------------
| Notifications (Phase 5B-3b)
|--------------------------------------------------------------------------
|
| How branded email and SMS are sent. Which notifications exist and what
| they may say: app/Platform/Notifications/NotificationCatalog.php; default
| wording: lang/{locale}/notifications.php; partner wording: the database.
|
*/

return [
    'mail' => [
        // What a partner's SPF record must include so our servers may send for its domain.
        'spf_include' => env('MAIL_SPF_INCLUDE', '_spf.onesolution.app'),
        // DKIM key size for partner domains.
        'dkim_bits' => (int) env('MAIL_DKIM_BITS', 2048),
    ],

    'sms' => [
        // log (writes a masked line to the log) until a real provider is added (Phase 5C).
        'driver' => env('SMS_DRIVER', 'log'),
        // Sender ID when a partner has no approved one of its own.
        'default_sender' => env('SMS_DEFAULT_SENDER', 'OneSolution'),
        // Local development only: the log driver also writes the text (e.g. to read a
        // sign-up code). Never honoured outside APP_ENV=local.
        'log_text' => (bool) env('SMS_LOG_TEXT', false),
    ],
];
