<?php

/*
|--------------------------------------------------------------------------
| Branding (interim, until Phase 5B partner_brands)
|--------------------------------------------------------------------------
|
| The house brand. A partner may override these keys in partners.settings
| ["brand"]; Phase 5B moves this into its own table with logos, domains
| and contrast checks. Values are validated before they reach any page.
|
*/

return [

    'house' => [
        'name' => env('HOUSE_PARTNER_NAME', 'One Solutions'),
        'primary_color' => env('BRAND_PRIMARY_COLOR', '#4F46E5'),
        'support_email' => env('BRAND_SUPPORT_EMAIL'),
    ],

];
