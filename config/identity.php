<?php

/*
|--------------------------------------------------------------------------
| Self-serve identity (Phase 5C-1)
|--------------------------------------------------------------------------
|
| Infrastructure settings only. Business choices (who may sign up, which
| plan, which countries get SMS, limits per hour) are rules: see
| app/Platform/Rules/core-rules.php, keys b2c.* and identity.*.
|
*/

return [

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        // Wrong codes before a challenge is closed for good.
        'max_attempts' => 5,
        // Codes sent for one challenge (first + resends).
        'max_sends' => 4,
        'resend_seconds' => 60,
    ],

    // Bot protection on sign-up, code requests and recovery.
    // none: no check (local development and tests only); turnstile: Cloudflare Turnstile.
    'bot_check' => [
        'driver' => env('BOT_CHECK_DRIVER', 'none'),
        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret' => env('TURNSTILE_SECRET'),
        ],
    ],

    // How phone numbers are written per country (facts, not choices). Which
    // countries may get SMS is the rule identity.allowed_phone_countries.
    // national: the number without the leading trunk 0, as a pattern.
    'phone_countries' => [
        'BD' => ['dial' => '880', 'trunk' => '0', 'national' => '1[3-9]\d{8}'],
        'IN' => ['dial' => '91', 'trunk' => '0', 'national' => '[6-9]\d{9}'],
        'PK' => ['dial' => '92', 'trunk' => '0', 'national' => '3\d{9}'],
        'NP' => ['dial' => '977', 'trunk' => '0', 'national' => '9[78]\d{8}'],
        'LK' => ['dial' => '94', 'trunk' => '0', 'national' => '7\d{8}'],
        'AE' => ['dial' => '971', 'trunk' => '0', 'national' => '5\d{8}'],
        'SA' => ['dial' => '966', 'trunk' => '0', 'national' => '5\d{8}'],
        'MY' => ['dial' => '60', 'trunk' => '0', 'national' => '1\d{8,9}'],
        'GB' => ['dial' => '44', 'trunk' => '0', 'national' => '7\d{9}'],
        'US' => ['dial' => '1', 'trunk' => '', 'national' => '[2-9]\d{2}[2-9]\d{6}'],
    ],

    // Throwaway inbox domains, one per line (identity.block_disposable_email).
    'disposable_domains_file' => resource_path('data/disposable-email-domains.txt'),

];
