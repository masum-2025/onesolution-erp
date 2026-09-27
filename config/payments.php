<?php

/*
|--------------------------------------------------------------------------
| Payment gateways (Phase 5C-2)
|--------------------------------------------------------------------------
|
| Credentials only. Which gateway a country offers is the rule
| `billing.payment_gateways`; prices and periods are plan data.
|
| SSLCommerz runs against its SANDBOX only in this build: there is no live
| address here on purpose, and the sandbox refuses to take payments in
| production (no plan can be unlocked with test money). Going live is a
| separate, reviewed change.
|
*/

return [

    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'base_url' => 'https://sandbox.sslcommerz.com',
        // Seconds to wait for the gateway before telling the person to try again.
        'timeout' => 20,
    ],

];
