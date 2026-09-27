<?php

/*
| United Arab Emirates. PLACEHOLDER values (weekend, fiscal year, tax profile): to be reviewed
| by an adviser before a client in this country goes live.
*/

return [
    'code' => 'AE',
    'name' => ['en' => 'United Arab Emirates', 'bn' => 'সংযুক্ত আরব আমিরাত', 'ar' => 'الإمارات العربية المتحدة'],
    'currency' => 'AED',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sat', 'sun'],
    'fiscal_year_start' => '01-01',
    'phone' => ['dial' => '971', 'trunk' => '0', 'national' => '5\d{8}', 'example' => '0501234567'],
    'address_format' => ['building', 'street', 'area', 'city', 'emirate'],
    'tax_profile' => 'ae_vat',
    'data_residency_region' => 'me',
    'default_locale' => 'ar',
    'locales' => ['ar', 'en'],
    'timezone' => 'Asia/Dubai',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER. Verify with an adviser.',
];
