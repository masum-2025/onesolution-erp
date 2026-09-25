<?php

/*
|--------------------------------------------------------------------------
| Plans (data, not code)
|--------------------------------------------------------------------------
|
| The source of truth for plans; `php artisan packaging:sync` mirrors it into
| the `plans` / `plan_prices` tables. Read at boot by PlanCatalog.
|
| modules: module keys included in the plan, or ['*'] for every module. A
|          module must also allow the plan in its own manifest (`plans`).
| prices:  integer minor units per currency and period (never floats).
|          PLACEHOLDER prices until the business confirms them.
| Limits (users, branches, storage) are rules `plans.max_*` with plan-level
| values in database/seeders/data/rule-values.php, so they change without
| code (per-client deals: partner layer, Phase 5B).
| Names: lang/{locale}/packaging.php "plans.{key}".
|
*/

return [
    [
        'key' => 'starter',
        'public' => true,
        'modules' => [
            'accounting', 'api_integration', 'attendance', 'crm', 'external_integrations',
            'factory_erp', 'hrm', 'inventory', 'multi_currency', 'multi_language',
            'offline_mode', 'payroll',
        ],
        'prices' => [
            ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 150000],
            ['currency' => 'BDT', 'period' => 'yearly', 'amount_minor' => 1500000],
            ['currency' => 'USD', 'period' => 'monthly', 'amount_minor' => 1500],
            ['currency' => 'USD', 'period' => 'yearly', 'amount_minor' => 15000],
        ],
    ],
    [
        'key' => 'business',
        'public' => true,
        'modules' => ['*'],
        'prices' => [
            ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 500000],
            ['currency' => 'BDT', 'period' => 'yearly', 'amount_minor' => 5000000],
            ['currency' => 'USD', 'period' => 'monthly', 'amount_minor' => 4900],
            ['currency' => 'USD', 'period' => 'yearly', 'amount_minor' => 49000],
        ],
    ],
    [
        'key' => 'enterprise',
        'public' => true,
        'modules' => ['*'],
        'prices' => [
            ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 1500000],
            ['currency' => 'BDT', 'period' => 'yearly', 'amount_minor' => 15000000],
            ['currency' => 'USD', 'period' => 'monthly', 'amount_minor' => 14900],
            ['currency' => 'USD', 'period' => 'yearly', 'amount_minor' => 149000],
        ],
    ],
];
