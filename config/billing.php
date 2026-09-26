<?php

/*
|--------------------------------------------------------------------------
| Billing (Phase 5B-3)
|--------------------------------------------------------------------------
|
| Who issues our invoices (legal details printed on every invoice and credit
| note) and how documents are numbered. Prices are data
| (database/seeders/data/plans.php, wholesale-prices.php, partner plans);
| tax, payment terms, partner currency and revenue share are rules.
|
*/

return [
    'issuer' => [
        'name' => env('BILLING_ISSUER_NAME', 'One Solutions'),
        'address' => env('BILLING_ISSUER_ADDRESS'),
        'tax_id' => env('BILLING_ISSUER_TAX_ID'),
        'email' => env('BILLING_ISSUER_EMAIL'),
    ],

    // Gap-free series per year: INV-2026-000001, CN-2026-000001.
    'series' => [
        'invoice' => 'INV',
        'credit_note' => 'CN',
    ],
];
