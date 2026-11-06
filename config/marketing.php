<?php

/*
|--------------------------------------------------------------------------
| Company websites (MKT)
|--------------------------------------------------------------------------
|
| The platform's own marketing website (the house partner's) is answered on
| these hosts, before the app runs. Comma separated, no port. Locally
| "onesolutions.localhost" works without any DNS (browsers send *.localhost
| to this computer). PLACEHOLDER until the real domain is chosen.
|
*/

return [
    'hosts' => env('MARKETING_HOSTS', ''),

    // The address search engines get (canonical links, sitemap). Empty: the address asked.
    'url' => env('MARKETING_URL', ''),

    // Rendered pages are kept this long (each publish starts afresh).
    'cache_seconds' => (int) env('MARKETING_CACHE_SECONDS', 300),

    // Requests one visitor (address) may make a minute.
    'per_minute' => (int) env('MARKETING_PER_MINUTE', 120),

    // Pictures one person may upload a minute (each is redrawn as two WebP copies).
    'uploads_per_minute' => (int) env('MARKETING_UPLOADS_PER_MINUTE', 20),

    // Contact form messages one visitor (address) may send a site an hour.
    'leads_per_hour' => (int) env('MARKETING_LEADS_PER_HOUR', 5),

    // Drafts kept for undo: the last published versions of a site.
    'versions_kept' => 20,
];
