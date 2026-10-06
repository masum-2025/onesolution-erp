<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

use Modules\Inventory\Dashboard\InventoryWidgets;
use Modules\Inventory\Dashboard\LowStock;

return [
    'key' => 'inventory',
    'name' => 'inventory::module.name',
    'description' => 'inventory::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['inventory.view', 'inventory.manage', 'inventory.approve'],
    // Whoever moves stock must not approve their own adjustments and counts.
    'separation_of_duties' => [['inventory.manage', 'inventory.approve']],
    'rules' => [
        [
            'key' => 'inventory.valuation_method',
            'type' => 'enum',
            'schema' => ['enum' => ['FIFO', 'weighted_average']],
            'default' => 'weighted_average',
            'label' => 'inventory::rules.valuation_method.label',
            'description' => 'inventory::rules.valuation_method.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'sensitive' => true,
            'category' => 'valuation',
            'sort_order' => 10,
        ],
        [
            'key' => 'inventory.allow_negative_stock',
            'type' => 'boolean',
            'default' => false,
            'label' => 'inventory::rules.allow_negative_stock.label',
            'description' => 'inventory::rules.allow_negative_stock.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'stock',
            'sort_order' => 20,
        ],
        [
            'key' => 'inventory.low_stock_alert_percent',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 100],
            'default' => 20,
            'label' => 'inventory::rules.low_stock_alert_percent.label',
            'description' => 'inventory::rules.low_stock_alert_percent.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'stock',
            'sort_order' => 30,
        ],
        [
            'key' => 'inventory.adjustment_approval_above',
            'type' => 'money',
            // An adjustment worth more waits for someone else; empty = never. Counts always do.
            'schema' => ['properties' => ['amount' => ['minimum' => 0]]],
            'nullable' => true,
            'default' => null,
            'label' => 'inventory::rules.adjustment_approval_above.label',
            'description' => 'inventory::rules.adjustment_approval_above.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'sensitive' => true,
            'category' => 'approval',
            'sort_order' => 40,
        ],
        [
            'key' => 'inventory.expiry_alert_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 365],
            'default' => 30,
            'label' => 'inventory::rules.expiry_alert_days.label',
            'description' => 'inventory::rules.expiry_alert_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'stock',
            'sort_order' => 50,
        ],
        [
            'key' => 'inventory.number_prefixes',
            'type' => 'json',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'receipt' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'],
                    'issue' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'],
                    'transfer' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'],
                    'adjustment' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'],
                    'count' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'],
                ],
                'additionalProperties' => false,
            ],
            'default' => ['receipt' => 'GRN', 'issue' => 'ISS', 'transfer' => 'TRF', 'adjustment' => 'ADJ', 'count' => 'CNT'],
            'label' => 'inventory::rules.number_prefixes.label',
            'description' => 'inventory::rules.number_prefixes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 60,
        ],
    ],
    'menu' => [
        [
            'key' => 'inventory',
            'label' => 'inventory::module.menu',
            'route' => '/inventory',
            'icon' => 'package',
            'order' => 50,
            'section' => 'business',
            'children' => [
                ['key' => 'stock', 'label' => 'inventory::module.menu_stock', 'route' => '/inventory/stock', 'permission' => 'inventory.view'],
                ['key' => 'items', 'label' => 'inventory::module.menu_items', 'route' => '/inventory/items', 'permission' => 'inventory.view'],
                ['key' => 'documents', 'label' => 'inventory::module.menu_documents', 'route' => '/inventory/documents', 'permission' => 'inventory.view'],
                ['key' => 'counts', 'label' => 'inventory::module.menu_counts', 'route' => '/inventory/counts', 'permission' => 'inventory.view'],
            ],
        ],
    ],
    // Other modules listen to these; payloads carry ids only.
    'events' => ['inventory.stock.low', 'inventory.moved'],
    // Where stock goes in the books (Accounting maps each to an account).
    'ledger_accounts' => [
        'inventory.stock' => ['label' => 'inventory::inventory.posting_keys.stock', 'type' => 'asset'],
        // Cleared by the supplier's bill: Accounting lets bills use this account on their lines.
        'inventory.grni' => ['label' => 'inventory::inventory.posting_keys.grni', 'type' => 'liability', 'cleared_by' => 'bills'],
        'inventory.cogs' => ['label' => 'inventory::inventory.posting_keys.cogs', 'type' => 'expense'],
        'inventory.adjustment' => ['label' => 'inventory::inventory.posting_keys.adjustment', 'type' => 'expense'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'value', 'label' => 'inventory::dashboard.value', 'type' => 'stat', 'provider' => InventoryWidgets::class, 'permission' => 'inventory.view', 'overview' => true],
        ['key' => 'low', 'label' => 'inventory::dashboard.low', 'type' => 'stat', 'provider' => LowStock::class, 'permission' => 'inventory.view'],
    ]],
    'attention' => [InventoryWidgets::class],
    'settings' => ['pages' => [
        ['key' => 'warehouses', 'label' => 'inventory::module.menu_warehouses', 'route' => '/inventory/warehouses', 'permission' => 'inventory.view'],
        ['key' => 'units', 'label' => 'inventory::module.menu_units', 'route' => '/inventory/units', 'permission' => 'inventory.view'],
        ['key' => 'categories', 'label' => 'inventory::module.menu_categories', 'route' => '/inventory/categories', 'permission' => 'inventory.view'],
    ]],
];
