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

    // How phone numbers are written per country is country data (Phase 6):
    // database/data/countries, "phone". Which countries may get SMS is the
    // rule identity.allowed_phone_countries.

    // Throwaway inbox domains, one per line (identity.block_disposable_email).
    'disposable_domains_file' => resource_path('data/disposable-email-domains.txt'),

];
