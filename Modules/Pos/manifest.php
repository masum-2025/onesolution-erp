<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\Pos\Dashboard\PosWidgets;
use Modules\Pos\Offline\SaleSync;

return [
    'key' => 'pos',
    'name' => 'pos::module.name',
    'description' => 'pos::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['inventory'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['pos.view', 'pos.sell', 'pos.supervise', 'pos.manage'],
    'rules' => [
        [
            'key' => 'pos.prices_include_tax',
            'type' => 'boolean',
            'default' => true,
            'label' => 'pos::rules.prices_include_tax.label',
            'description' => 'pos::rules.prices_include_tax.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'tax',
            'sort_order' => 10,
        ],
        [
            'key' => 'pos.max_discount_percent',
            'type' => 'decimal',
            // Of a sale, what a cashier may give off; more needs pos.supervise.
            'schema' => ['pattern' => '^\d{1,3}(\.\d{1,2})?$'],
            'default' => '10',
            'label' => 'pos::rules.max_discount_percent.label',
            'description' => 'pos::rules.max_discount_percent.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'selling',
            'sort_order' => 20,
        ],
        [
            'key' => 'pos.cash_variance_allowed',
            'type' => 'money',
            // A shift's cash difference within this closes it; beyond, a supervisor reviews. Empty: any difference is reviewed.
            'schema' => ['properties' => ['amount' => ['minimum' => 0]]],
            'nullable' => true,
            'default' => null,
            'label' => 'pos::rules.cash_variance_allowed.label',
            'description' => 'pos::rules.cash_variance_allowed.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'cash',
            'sort_order' => 30,
        ],
        [
            'key' => 'pos.return_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 365],
            'default' => 30,
            'label' => 'pos::rules.return_days.label',
            'description' => 'pos::rules.return_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'selling',
            'sort_order' => 40,
        ],
        [
            'key' => 'pos.offline_sales',
            'type' => 'boolean',
            'default' => true,
            'label' => 'pos::rules.offline_sales.label',
            'description' => 'pos::rules.offline_sales.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'category' => 'selling',
            'sort_order' => 50,
        ],
        [
            'key' => 'pos.number_prefixes',
            'type' => 'json',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'sale' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$'],
                    'return' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$'],
                ],
                'additionalProperties' => false,
            ],
            'default' => ['sale' => 'R', 'return' => 'RT'],
            'label' => 'pos::rules.number_prefixes.label',
            'description' => 'pos::rules.number_prefixes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 60,
        ],
    ],
    'menu' => [
        [
            'key' => 'pos',
            'label' => 'pos::module.menu',
            'route' => '/pos',
            'icon' => 'shopping-cart',
            'order' => 55,
            'section' => 'business',
            'children' => [
                ['key' => 'till', 'label' => 'pos::module.menu_till', 'route' => '/pos', 'permission' => 'pos.sell'],
                ['key' => 'sales', 'label' => 'pos::module.menu_sales', 'route' => '/pos/sales', 'permission' => 'pos.view'],
                ['key' => 'shifts', 'label' => 'pos::module.menu_shifts', 'route' => '/pos/shifts', 'permission' => 'pos.view'],
                ['key' => 'reports', 'label' => 'pos::module.menu_reports', 'route' => '/pos/reports', 'permission' => 'pos.supervise'],
            ],
        ],
    ],
    'events' => [],
    // Money in and out at the counter (Accounting maps each to an account); cost uses Inventory's keys.
    'ledger_accounts' => [
        'pos.cash' => ['label' => 'pos::pos.posting_keys.cash', 'type' => 'asset'],
        'pos.card' => ['label' => 'pos::pos.posting_keys.card', 'type' => 'asset'],
        'pos.mobile' => ['label' => 'pos::pos.posting_keys.mobile', 'type' => 'asset'],
        'pos.sales' => ['label' => 'pos::pos.posting_keys.sales', 'type' => 'income'],
        'pos.tax_output' => ['label' => 'pos::pos.posting_keys.tax_output', 'type' => 'liability'],
        'pos.cash_variance' => ['label' => 'pos::pos.posting_keys.cash_variance', 'type' => 'expense'],
    ],
    // Selling while offline (money: append-only, with offline_mode.allow_offline_payments).
    'sync_records' => [SaleSync::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'today', 'label' => 'pos::dashboard.today', 'type' => 'stat', 'provider' => PosWidgets::class, 'permission' => 'pos.view', 'overview' => true],
    ]],
    'attention' => [PosWidgets::class],
    'settings' => ['pages' => [
        ['key' => 'registers', 'label' => 'pos::module.menu_registers', 'route' => '/pos/registers', 'permission' => 'pos.view'],
    ]],
];
