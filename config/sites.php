<?php

/*
|--------------------------------------------------------------------------
| Public sites (WEB-1)
|--------------------------------------------------------------------------
|
| Clients' public websites are served at "{slug}.{domain}" (a wildcard DNS
| record and certificate for *.{domain} point here) and at "/s/{slug}" on
| the app's own host. PLACEHOLDER domain until the real one is chosen.
|
*/

return [
    'domain' => env('SITES_DOMAIN', 'sites.localhost'),

    // Rendered pages are kept this long (each publish or notice change starts afresh).
    'cache_seconds' => (int) env('SITES_CACHE_SECONDS', 300),

    // Requests one visitor (address) may make a minute.
    'per_minute' => (int) env('SITES_PER_MINUTE', 120),
];
