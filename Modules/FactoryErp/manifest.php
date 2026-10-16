<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\FactoryErp\Dashboard\FactoryWidgets;
use Modules\FactoryErp\Dashboard\ShippingSoon;

return [
    'key' => 'factory_erp',
    'name' => 'factory_erp::module.name',
    'description' => 'factory_erp::module.description',
    'version' => '1.1.0',
    'category' => 'business',
    // Materials come from Inventory (its Stock service), buyers are CRM organization
    // contacts (its Organizations service); materials on the floor post to Accounting
    // as work in progress (its Ledger).
    'requires' => ['inventory', 'accounting', 'crm'],
    'sectors' => ['factory'],
    'plans' => ['*'],
    'permissions' => ['factory_erp.view', 'factory_erp.merchandise', 'factory_erp.issue', 'factory_erp.produce', 'factory_erp.manage'],
    'rules' => [
        [
            'key' => 'factory_erp.consumption_basis',
            'type' => 'enum',
            // Garments write consumption per dozen; kept per style when it is made.
            'schema' => ['enum' => ['dozen', 'piece']],
            'default' => 'dozen',
            'label' => 'factory_erp::rules.consumption_basis.label',
            'description' => 'factory_erp::rules.consumption_basis.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'materials',
            'sort_order' => 10,
        ],
        [
            'key' => 'factory_erp.default_wastage_bp',
            'type' => 'integer',
            // Wastage a new BOM line starts with, in basis points (500 = 5%).
            'schema' => ['minimum' => 0, 'maximum' => 5000],
            'default' => 500,
            'label' => 'factory_erp::rules.default_wastage_bp.label',
            'description' => 'factory_erp::rules.default_wastage_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'materials',
            'sort_order' => 20,
        ],
        [
            'key' => 'factory_erp.order_currency',
            'type' => 'string',
            // Export orders are priced in the buyer's currency.
            'schema' => ['pattern' => '^[A-Z]{3}$'],
            'default' => 'USD',
            'label' => 'factory_erp::rules.order_currency.label',
            'description' => 'factory_erp::rules.order_currency.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'orders',
            'sort_order' => 30,
        ],
        [
            'key' => 'factory_erp.order_number_prefix',
            'type' => 'string',
            'schema' => ['pattern' => '^[A-Z0-9]{1,8}$'],
            'default' => 'FO',
            'label' => 'factory_erp::rules.order_number_prefix.label',
            'description' => 'factory_erp::rules.order_number_prefix.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'orders',
            'sort_order' => 40,
        ],
        [
            'key' => 'factory_erp.ship_warning_days',
            'type' => 'integer',
            // Orders shipping within this many days show on the home and, not started yet, in the bell.
            'schema' => ['minimum' => 1, 'maximum' => 120],
            'default' => 30,
            'label' => 'factory_erp::rules.ship_warning_days.label',
            'description' => 'factory_erp::rules.ship_warning_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'orders',
            'sort_order' => 50,
        ],
        [
            'key' => 'factory_erp.over_issue',
            'type' => 'enum',
            // More material to an order than its bill of materials needs: asked again (warn) or refused (block).
            'schema' => ['enum' => ['warn', 'block']],
            'default' => 'warn',
            'label' => 'factory_erp::rules.over_issue.label',
            'description' => 'factory_erp::rules.over_issue.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'floor',
            'sort_order' => 60,
        ],
        [
            'key' => 'factory_erp.over_cut_bp',
            'type' => 'integer',
            // Pieces cut above the order per size and colour, in basis points (300 = 3%).
            'schema' => ['minimum' => 0, 'maximum' => 2000],
            'default' => 300,
            'label' => 'factory_erp::rules.over_cut_bp.label',
            'description' => 'factory_erp::rules.over_cut_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'floor',
            'sort_order' => 70,
        ],
    ],
    'menu' => [
        [
            'key' => 'factory_erp',
            'label' => 'factory_erp::module.menu',
            'route' => '/factory',
            'icon' => 'factory',
            'order' => 70,
            'children' => [
                ['key' => 'orders', 'label' => 'factory_erp::module.menu_orders', 'route' => '/factory', 'permission' => 'factory_erp.view'],
                ['key' => 'styles', 'label' => 'factory_erp::module.menu_styles', 'route' => '/factory/styles', 'permission' => 'factory_erp.view'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'order', 'label' => 'factory_erp::module.new_order', 'route' => '/factory?new=1', 'permission' => 'factory_erp.merchandise', 'icon' => 'factory'],
    ],
    'events' => [],
    // Materials on the floor until finished goods come in (Accounting maps it to an account).
    'ledger_accounts' => [
        'factory_erp.wip' => ['label' => 'factory_erp::factory.posting_keys.wip', 'type' => 'asset'],
    ],
    'attention' => [FactoryWidgets::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'open', 'label' => 'factory_erp::dashboard.open', 'type' => 'stat', 'provider' => FactoryWidgets::class, 'permission' => 'factory_erp.view', 'overview' => true],
        ['key' => 'shipping', 'label' => 'factory_erp::dashboard.shipping', 'type' => 'list', 'size' => 2, 'provider' => ShippingSoon::class, 'permission' => 'factory_erp.view'],
    ]],
    'settings' => ['pages' => [
        ['key' => 'fields', 'label' => 'factory_erp::module.menu_fields', 'route' => '/factory/fields', 'permission' => 'factory_erp.manage'],
    ]],
];
