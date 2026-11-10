<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers live in rules, never here. Selling goes through POS (its
| shifts, cash and books); stock, batches and expiry are Inventory's.
*/

use Modules\Pharmacy\Dashboard\PharmacyWidgets;

$rule = fn (string $name, string $type, mixed $default, array $levels, string $category, int $order, array $extra = []) => [
    'key' => "pharmacy.{$name}",
    'type' => $type,
    'default' => $default,
    'label' => "pharmacy::rules.{$name}.label",
    'description' => "pharmacy::rules.{$name}.description",
    'overridable_levels' => $levels,
    'category' => $category,
    'sort_order' => $order,
    ...$extra,
];
$toCompany = ['platform', 'partner', 'group', 'company'];
$toBranch = ['platform', 'partner', 'group', 'company', 'branch'];

return [
    'key' => 'pharmacy',
    'name' => 'pharmacy::module.name',
    'description' => 'pharmacy::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['inventory', 'pos'],
    // Any sector may turn it on (a general store selling medicines); the pharmacy and hospital packages (H-5) turn it on by default.
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['pharmacy.view', 'pharmacy.sell', 'pharmacy.manage', 'pharmacy.controlled', 'pharmacy.approve'],
    'rules' => [
        $rule('near_expiry_days', 'integer', 30, $toBranch, 'expiry', 10, ['schema' => ['minimum' => 0, 'maximum' => 365]]),
        $rule('return_before_expiry_days', 'integer', 90, $toBranch, 'expiry', 20, ['schema' => ['minimum' => 0, 'maximum' => 730]]),
        $rule('block_expired_sale', 'boolean', true, $toCompany, 'selling', 30),
        $rule('block_above_mrp', 'boolean', true, $toCompany, 'selling', 40, ['country_specific' => true]),
        $rule('controlled_requires_prescription', 'boolean', true, $toCompany, 'selling', 50, ['country_specific' => true, 'sensitive' => true]),
        $rule('sell_loose_pieces', 'boolean', true, $toBranch, 'selling', 60),
        $rule('number_prefixes', 'json', ['return' => 'SR'], $toCompany, 'numbering', 70, ['schema' => [
            'type' => 'object',
            'properties' => ['return' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$']],
            'additionalProperties' => false,
        ]]),
    ],
    'menu' => [
        [
            'key' => 'pharmacy',
            'label' => 'pharmacy::module.menu',
            'route' => '/pharmacy/sell',
            'icon' => 'pill',
            'order' => 56,
            'section' => 'business',
            'children' => [
                ['key' => 'sell', 'label' => 'pharmacy::module.menu_sell', 'route' => '/pharmacy/sell', 'permission' => 'pharmacy.sell'],
                ['key' => 'medicines', 'label' => 'pharmacy::module.menu_medicines', 'route' => '/pharmacy/medicines', 'permission' => 'pharmacy.view'],
                ['key' => 'purchases', 'label' => 'pharmacy::module.menu_purchases', 'route' => '/pharmacy/purchases', 'permission' => 'pharmacy.manage'],
                ['key' => 'stock', 'label' => 'pharmacy::module.menu_stock', 'route' => '/pharmacy/stock', 'permission' => 'pharmacy.view'],
                ['key' => 'returns', 'label' => 'pharmacy::module.menu_returns', 'route' => '/pharmacy/returns', 'permission' => 'pharmacy.view'],
                ['key' => 'controlled', 'label' => 'pharmacy::module.menu_controlled', 'route' => '/pharmacy/controlled', 'permission' => 'pharmacy.controlled'],
                ['key' => 'reports', 'label' => 'pharmacy::module.menu_reports', 'route' => '/pharmacy/reports', 'permission' => 'pharmacy.approve'],
            ],
        ],
    ],
    // Payloads carry ids, numbers and amounts only (never a patient's name).
    'events' => ['pharmacy.sale_made', 'pharmacy.controlled_dispensed'],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'expiring', 'label' => 'pharmacy::dashboard.expiring', 'type' => 'stat', 'provider' => PharmacyWidgets::class, 'permission' => 'pharmacy.view'],
    ]],
    'attention' => [PharmacyWidgets::class],
    'settings' => ['pages' => [
        ['key' => 'generics', 'label' => 'pharmacy::module.menu_generics', 'route' => '/pharmacy/generics', 'permission' => 'pharmacy.view'],
        ['key' => 'manufacturers', 'label' => 'pharmacy::module.menu_manufacturers', 'route' => '/pharmacy/manufacturers', 'permission' => 'pharmacy.view'],
    ]],
];
