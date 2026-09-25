<?php

/*
|--------------------------------------------------------------------------
| Module system (Phase 2)
|--------------------------------------------------------------------------
|
| Per-organization module on/off. (config/modules.php belongs to
| nwidart/laravel-modules and only handles loading the module folders.)
| Values marked "rule" move to the rule engine in Phase 3.
|
*/

return [

    // rule: days between a purge request and the actual data deletion.
    'purge_delay_days' => (int) env('MODULES_PURGE_DELAY_DAYS', 7),

    // How long a resolved module map stays cached per organization, in minutes.
    'cache_ttl_minutes' => (int) env('MODULES_CACHE_TTL_MINUTES', 1440),

    // Sanctum token name prefix for machine-to-machine integration tokens.
    // Revoked automatically when api_integration is turned off.
    'integration_token_prefix' => 'integration:',

];
