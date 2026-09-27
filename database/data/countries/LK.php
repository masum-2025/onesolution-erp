<?php

/*
| Sri Lanka. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'LK',
    'name' => ['en' => 'Sri Lanka', 'bn' => 'শ্রীলঙ্কা', 'ar' => 'سريلانكا'],
    'currency' => 'LKR',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sat', 'sun'],
    'fiscal_year_start' => '04-01',
    'phone' => ['dial' => '94', 'trunk' => '0', 'national' => '7\d{8}', 'example' => '0712345678'],
    'address_format' => ['line1', 'line2', 'city', 'postcode'],
    'tax_profile' => 'lk_vat',
    'data_residency_region' => 'lk',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Asia/Colombo',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
