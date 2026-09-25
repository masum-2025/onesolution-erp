<?php

/*
|--------------------------------------------------------------------------
| Tenancy foundation
|--------------------------------------------------------------------------
|
| Bootstrapping values only. Business tunables (organization structure, max
| depth, token lifetime) are rules: see app/Platform/Rules/core-rules.php and
| Rules::get('tenancy.*').
|
*/

return [

    // The partner that owns direct (non white-label) clients. Branding is data.
    'house_partner' => [
        'name' => env('HOUSE_PARTNER_NAME', 'One Solutions'),
        'slug' => env('HOUSE_PARTNER_SLUG', 'house'),
    ],

    // Platform fallbacks when no organization in the chain sets a value.
    'defaults' => [
        'country_code' => env('PLATFORM_DEFAULT_COUNTRY', 'BD'),
        'default_locale' => env('PLATFORM_DEFAULT_LOCALE', 'en'),
        'timezone' => 'UTC',
        'currency_code' => env('PLATFORM_DEFAULT_CURRENCY', 'BDT'),
        'region' => env('PLATFORM_DEFAULT_REGION', 'bd'),
        // Interim plan (see config/plans.php) until Phase 5 billing.
        'plan_key' => env('PLATFORM_DEFAULT_PLAN', 'starter'),
    ],

    // Locales that organization names and UI messages may use.
    'supported_locales' => ['en', 'bn'],

    // Requests per minute. Login runs before any tenant is known, so these
    // stay infrastructure settings rather than rules.
    'throttle' => [
        'login' => (int) env('TENANCY_THROTTLE_LOGIN', 5),
        'sensitive' => (int) env('TENANCY_THROTTLE_SENSITIVE', 20),
    ],

];
