<?php

/*
|--------------------------------------------------------------------------
| Payment gateways (Phase 5C-2, Phase 6)
|--------------------------------------------------------------------------
|
| Credentials of the platform's own accounts, and gateway addresses. Which
| gateway a country offers is a rule (`billing.payment_gateways` for paying
| us, `online_payments.gateways` for a client's own merchant accounts);
| prices and periods are plan data.
|
| The platform's own SSLCommerz account runs against the SANDBOX only in this
| build, and the sandbox refuses to take payments in production (no plan can
| be unlocked with test money). A client's merchant account may use the live
| address only where the rule `online_payments.live_mode_allowed` is on.
|
*/

return [

    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'base_url' => 'https://sandbox.sslcommerz.com',
        'live_base_url' => 'https://securepay.sslcommerz.com',
        // Seconds to wait for the gateway before telling the person to try again.
        'timeout' => 20,
    ],

];
