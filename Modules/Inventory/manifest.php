<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'inventory',
    'name' => 'inventory::module.name',
    'description' => 'inventory::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['inventory.view', 'inventory.manage'],
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
    ],
    'menu' => [
        [
            'key' => 'inventory',
            'label' => 'inventory::module.menu',
            'route' => '/inventory',
            'icon' => 'package',
            'order' => 50,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the module's business screens (its rules show already).
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
