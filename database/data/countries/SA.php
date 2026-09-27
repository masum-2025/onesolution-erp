<?php

/*
| Saudi Arabia. PLACEHOLDER values: to be reviewed by an adviser before a
| Saudi client goes live (weekend, fiscal year, VAT profile).
*/

return [
    'code' => 'SA',
    'name' => ['en' => 'Saudi Arabia', 'bn' => 'সৌদি আরব', 'ar' => 'المملكة العربية السعودية'],
    'currency' => 'SAR',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'sun',
    'weekend_days' => ['fri', 'sat'],
    'fiscal_year_start' => '01-01',
    'phone' => ['dial' => '966', 'trunk' => '0', 'national' => '5\d{8}', 'example' => '0512345678'],
    'address_format' => ['building', 'street', 'district', 'city', 'postcode'],
    'tax_profile' => 'sa_vat',
    'data_residency_region' => 'me',
    'default_locale' => 'ar',
    'locales' => ['ar', 'en'],
    'timezone' => 'Asia/Riyadh',
    'payment_gateways' => [],
    'merchant_gateways' => [],
    'source' => 'PLACEHOLDER: Friday-Saturday weekend, calendar fiscal year, VAT 15% (ZATCA). Verify with an adviser.',
];
