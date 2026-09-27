<?php

/*
| Pakistan. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'PK',
    'name' => ['en' => 'Pakistan', 'bn' => 'পাকিস্তান', 'ar' => 'باكستان'],
    'currency' => 'PKR',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sun'],
    'fiscal_year_start' => '07-01',
    'phone' => ['dial' => '92', 'trunk' => '0', 'national' => '3\d{9}', 'example' => '03001234567'],
    'address_format' => ['line1', 'line2', 'city', 'province', 'postcode'],
    'tax_profile' => 'pk_gst',
    'data_residency_region' => 'pk',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Asia/Karachi',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
