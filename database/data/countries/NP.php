<?php

/*
| Nepal. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'NP',
    'name' => ['en' => 'Nepal', 'bn' => 'নেপাল', 'ar' => 'نيبال'],
    'currency' => 'NPR',
    'currency_decimals' => 2,
    'date_format' => 'YYYY-MM-DD',
    'week_start' => 'sun',
    'weekend_days' => ['sat'],
    'fiscal_year_start' => '07-16',
    'phone' => ['dial' => '977', 'trunk' => '0', 'national' => '9[78]\d{8}', 'example' => '09812345678'],
    'address_format' => ['line1', 'line2', 'city', 'province', 'postcode'],
    'tax_profile' => 'np_vat',
    'data_residency_region' => 'np',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Asia/Kathmandu',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
