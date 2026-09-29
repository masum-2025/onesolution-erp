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

    // Two-step sign-in (Phase 8-1). Whether it is required, the grace period
    // and step-up time are rules (identity.mfa_*, identity.step_up_minutes).
    'two_factor' => [
        // A sign-in waiting for its second step, and how many wrong tries it gets.
        'challenge_minutes' => 5,
        'max_attempts' => 5,
        // A passkey ceremony (options -> answer) must finish within this.
        'passkey_timeout_seconds' => 120,
        // Passkeys need HTTPS; these hosts may use plain HTTP (local development only).
        'passkey_insecure_hosts' => array_values(array_filter(explode(',', (string) env('PASSKEY_INSECURE_HOSTS', 'localhost')))),
        // A reset request an admin made waits this long for a second admin.
        'reset_request_hours' => 24,
    ],

];
