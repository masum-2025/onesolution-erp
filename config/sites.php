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

    // A client's own domain points here: a CNAME to this name (www.school.edu.bd), or an A record to
    // this address for a bare domain. PLACEHOLDERS until the sites server exists.
    'cname_target' => env('SITES_CNAME_TARGET', 'cname.'.env('SITES_DOMAIN', 'sites.localhost')),
    'server_ip' => env('SITES_SERVER_IP'),

    // Requests one visitor (address) may make a minute.
    'per_minute' => (int) env('SITES_PER_MINUTE', 120),
];
