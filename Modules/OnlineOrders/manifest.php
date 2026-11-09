<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('online_orders.*')).
*/

$rule = fn (string $key, string $type, mixed $default, array $levels, int $order, string $category, array $extra = []) => [
    'key' => "online_orders.{$key}",
    'type' => $type,
    'default' => $default,
    'label' => "online_orders::rules.{$key}.label",
    'description' => "online_orders::rules.{$key}.description",
    'overridable_levels' => $levels,
    'category' => $category,
    'sort_order' => $order,
    ...$extra,
];
$company = ['platform', 'partner', 'plan', 'group', 'company'];
$branch = [...$company, 'branch'];

return [
    'key' => 'online_orders',
    'name' => 'online_orders::module.name',
    'description' => 'online_orders::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Items, prices and stock are Inventory's; CRM (customers) and Accounting (books) are used when on.
    'requires' => ['inventory'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // See the orders of a branch and below.
        'online_orders.view',
        // Take orders, change drafts, confirm, pack, ship, mark delivered, take payments, cancel before shipping.
        'online_orders.manage',
        // Cancel an order already shipped (it came back), take a delivered order back, give a discount above the limit.
        'online_orders.supervise',
    ],
    'rules' => [
        // Order numbers: the prefix, the year and a running number (OO-2026-000001).
        $rule('number_prefix', 'string', 'OO', $company, 10, 'numbering', ['schema' => ['pattern' => '^[A-Za-z0-9]{1,6}$']]),
        $rule('prices_include_tax', 'boolean', true, $company, 20, 'tax', ['country_specific' => true]),
        // Whether customers may pay the courier on delivery; off: orders are paid before they are sent.
        $rule('cod_allowed', 'boolean', true, $branch, 30, 'selling'),
        // Delivery areas and what each costs (minor units of the company's currency): [{code, name: {en, bn}, charge_minor}].
        $rule('delivery_zones', 'json', [], $branch, 40, 'delivery', [
            'country_specific' => true,
            'schema' => [
                'type' => 'array',
                'maxItems' => 30,
                'items' => [
                    'type' => 'object',
                    'required' => ['code', 'name', 'charge_minor'],
                    'properties' => [
                        'code' => ['type' => 'string', 'pattern' => '^[a-z0-9_]{1,20}$'],
                        'name' => ['type' => 'object'],
                        'charge_minor' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]),
        // Of an order's goods, what staff may give off; more needs online_orders.supervise.
        $rule('max_discount_percent', 'decimal', '10', $branch, 50, 'selling', ['schema' => ['pattern' => '^\d{1,3}(\.\d{1,2})?$']]),
        // Days after delivery a customer may send an order back.
        $rule('return_days', 'integer', 7, $branch, 60, 'selling', ['country_specific' => true, 'schema' => ['minimum' => 0, 'maximum' => 365]]),
    ],
    'menu' => [
        [
            'key' => 'online_orders',
            'label' => 'online_orders::module.menu',
            'route' => '/online-orders',
            'icon' => 'package',
            'order' => 56,
            'section' => 'business',
            'children' => [
                ['key' => 'orders', 'label' => 'online_orders::module.menu_orders', 'route' => '/online-orders', 'permission' => 'online_orders.view'],
                ['key' => 'new', 'label' => 'online_orders::module.menu_new', 'route' => '/online-orders/new', 'permission' => 'online_orders.manage'],
            ],
        ],
    ],
    'quick_actions' => [],
    // Other modules (Facebook selling, CRM) listen to these; payloads carry ids and amounts only.
    'events' => ['online_orders.confirmed', 'online_orders.shipped', 'online_orders.delivered', 'online_orders.cancelled'],
    // Accounting maps each to an account; cost uses Inventory's keys.
    'ledger_accounts' => [
        'online_orders.receivable' => ['label' => 'online_orders::orders.posting_keys.receivable', 'type' => 'asset'],
        'online_orders.sales' => ['label' => 'online_orders::orders.posting_keys.sales', 'type' => 'income'],
        'online_orders.delivery_income' => ['label' => 'online_orders::orders.posting_keys.delivery_income', 'type' => 'income'],
        'online_orders.tax_output' => ['label' => 'online_orders::orders.posting_keys.tax_output', 'type' => 'liability'],
        'online_orders.cash' => ['label' => 'online_orders::orders.posting_keys.cash', 'type' => 'asset'],
        'online_orders.mobile' => ['label' => 'online_orders::orders.posting_keys.mobile', 'type' => 'asset'],
        'online_orders.bank' => ['label' => 'online_orders::orders.posting_keys.bank', 'type' => 'asset'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    // Widgets (orders waiting, today's deliveries) come with the Facebook screens; the rules are its settings.
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
