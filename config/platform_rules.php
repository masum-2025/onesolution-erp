<?php

/*
|--------------------------------------------------------------------------
| Rule engine (Phase 3)
|--------------------------------------------------------------------------
*/

return [

    // Longest time a resolved rule map stays cached (shorter when a
    // future-dated value is about to take effect).
    'cache_ttl_minutes' => (int) env('RULES_CACHE_TTL_MINUTES', 1440),

    // Upper bound for "preview impact" (descendant organizations checked).
    'preview_limit' => 500,

];
