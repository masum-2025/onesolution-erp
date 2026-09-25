<?php

/*
|--------------------------------------------------------------------------
| Tenancy foundation (Phase 1)
|--------------------------------------------------------------------------
|
| The rule engine (Phase 3) is built on top of this hierarchy, so the few
| tunables Phase 1 needs live here for now. Each one marked "rule" moves to
| Rules::get('tenancy.*') in Phase 3 without changing calling code.
|
*/

return [

    // The partner that owns direct (non white-label) clients. Branding is data.
    'house_partner' => [
        'name' => env('HOUSE_PARTNER_NAME', 'One Solutions'),
        'slug' => env('HOUSE_PARTNER_SLUG', 'house'),
    ],

    // rule: which organization type may sit under which parent type ("root" = no parent).
    'allowed_parents' => [
        'group' => ['root'],
        'company' => ['group'],
        'branch' => ['company'],
        'department' => ['branch', 'company'],
    ],

    // rule: deepest allowed level (root group = 0).
    'max_depth' => (int) env('TENANCY_MAX_DEPTH', 6),

    // Platform fallbacks when no organization in the chain sets a value.
    'defaults' => [
        'country_code' => env('PLATFORM_DEFAULT_COUNTRY', 'BD'),
        'default_locale' => env('PLATFORM_DEFAULT_LOCALE', 'en'),
        'timezone' => 'UTC',
        'currency_code' => env('PLATFORM_DEFAULT_CURRENCY', 'BDT'),
        'region' => env('PLATFORM_DEFAULT_REGION', 'bd'),
    ],

    // Locales that organization names and UI messages may use.
    'supported_locales' => ['en', 'bn'],

    // rule: lifetime of an API token, in minutes.
    'token_ttl_minutes' => (int) env('TENANCY_TOKEN_TTL_MINUTES', 720),

    // rule: requests per minute.
    'throttle' => [
        'login' => (int) env('TENANCY_THROTTLE_LOGIN', 5),
        'sensitive' => (int) env('TENANCY_THROTTLE_SENSITIVE', 20),
    ],

];
