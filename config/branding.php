<?php

/*
|--------------------------------------------------------------------------
| Branding (interim, until Phase 5B partner_brands)
|--------------------------------------------------------------------------
|
| The house brand. A partner may override these keys in partners.settings
| ["brand"]; Phase 5B moves this into its own table with uploads, domains
| and contrast checks. Values are validated before they reach any page.
|
| logo_url: full logo (mark + name) for light backgrounds.
| mark_url: the symbol alone (sidebar, favicon), square, transparent.
| Both must be paths on this site (the page's CSP allows no other origin).
|
*/

return [

    'house' => [
        'name' => env('HOUSE_PARTNER_NAME', 'One Solutions'),
        'primary_color' => env('BRAND_PRIMARY_COLOR', '#2B4C9B'),
        'support_email' => env('BRAND_SUPPORT_EMAIL'),
        'logo_url' => env('BRAND_LOGO_URL', '/brand/house/logo.png'),
        'mark_url' => env('BRAND_MARK_URL', '/brand/house/mark.png'),
        'tagline' => [
            'en' => 'the symbol of freedom',
            'bn' => 'স্বাধীনতার প্রতীক',
        ],
    ],

];
