<?php

/*
| Tax codes for the tax profile "bd_vat" (Bangladesh VAT), copied into a
| company's own tax codes when it sets up its books (or by
| `php artisan accounting:seed-tax-codes`). The company may rename, add or
| switch them off afterwards. A country's profile is in its data file
| (database/data/countries/BD.php: tax_profile).
|
| rate_bp: basis points (15% = 1500). kind: standard | reduced | zero | exempt.
| applies_to: sales | purchases | both.
|
| PLACEHOLDER rates from the VAT and Supplementary Duty Act 2012 schedules:
| a tax adviser must confirm them (and supplementary duty, withholding and
| truncated rates) before production use.
*/

return [
    'profile' => 'bd_vat',
    'source' => 'VAT and Supplementary Duty Act 2012 (standard 15%; reduced rates in the Third Schedule). Verify with an adviser.',
    'codes' => [
        ['code' => 'VAT15', 'name' => ['en' => 'VAT 15%', 'bn' => 'ভ্যাট ১৫%'], 'rate_bp' => 1500, 'kind' => 'standard', 'applies_to' => 'both'],
        ['code' => 'VAT10', 'name' => ['en' => 'VAT 10%', 'bn' => 'ভ্যাট ১০%'], 'rate_bp' => 1000, 'kind' => 'reduced', 'applies_to' => 'both'],
        ['code' => 'VAT7.5', 'name' => ['en' => 'VAT 7.5%', 'bn' => 'ভ্যাট ৭.৫%'], 'rate_bp' => 750, 'kind' => 'reduced', 'applies_to' => 'both'],
        ['code' => 'VAT5', 'name' => ['en' => 'VAT 5%', 'bn' => 'ভ্যাট ৫%'], 'rate_bp' => 500, 'kind' => 'reduced', 'applies_to' => 'both'],
        ['code' => 'ZERO', 'name' => ['en' => 'Zero-rated (exports)', 'bn' => 'শূন্য হার (রপ্তানি)'], 'rate_bp' => 0, 'kind' => 'zero', 'applies_to' => 'both'],
        ['code' => 'EXEMPT', 'name' => ['en' => 'Exempt', 'bn' => 'অব্যাহতিপ্রাপ্ত'], 'rate_bp' => 0, 'kind' => 'exempt', 'applies_to' => 'both'],
    ],
];
