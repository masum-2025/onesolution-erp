<?php

/*
|--------------------------------------------------------------------------
| Default wholesale prices (data, not code)
|--------------------------------------------------------------------------
|
| What we charge a wholesale partner per month for each client on a plan:
| per_client (a flat amount per client) or per_seat (per staff user).
| `php artisan billing:sync-prices` records these as the defaults for every
| partner (partner_id null); a price for one partner is set with
| `php artisan billing:wholesale-price`. Integer minor units, never floats.
|
| PLACEHOLDER prices until the business confirms them.
|
*/

return [
    ['plan' => 'starter', 'currency' => 'USD', 'unit' => 'per_client', 'amount_minor' => 900],
    ['plan' => 'starter', 'currency' => 'BDT', 'unit' => 'per_client', 'amount_minor' => 90000],
    ['plan' => 'business', 'currency' => 'USD', 'unit' => 'per_client', 'amount_minor' => 2900],
    ['plan' => 'business', 'currency' => 'BDT', 'unit' => 'per_client', 'amount_minor' => 290000],
    ['plan' => 'enterprise', 'currency' => 'USD', 'unit' => 'per_seat', 'amount_minor' => 300],
    ['plan' => 'enterprise', 'currency' => 'BDT', 'unit' => 'per_seat', 'amount_minor' => 30000],
    // Personal plans (white-label B2C): free stays free; Plus per person.
    ['plan' => 'personal_free', 'currency' => 'USD', 'unit' => 'per_client', 'amount_minor' => 0],
    ['plan' => 'personal_free', 'currency' => 'BDT', 'unit' => 'per_client', 'amount_minor' => 0],
    ['plan' => 'personal_plus', 'currency' => 'USD', 'unit' => 'per_client', 'amount_minor' => 200],
    ['plan' => 'personal_plus', 'currency' => 'BDT', 'unit' => 'per_client', 'amount_minor' => 12000],
];
