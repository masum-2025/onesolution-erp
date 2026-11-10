<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers live in rules, never here. Patients come from the
| Patients directory; tests are billed through Patient billing's
| PatientCharges; other modules order tests through Contracts\TestOrders.
*/

use Modules\Diagnostics\Dashboard\LabWidgets;

$rule = fn (string $name, string $type, mixed $default, array $levels, string $category, int $order, array $extra = []) => [
    'key' => "diagnostics.{$name}",
    'type' => $type,
    'default' => $default,
    'label' => "diagnostics::rules.{$name}.label",
    'description' => "diagnostics::rules.{$name}.description",
    'overridable_levels' => $levels,
    'category' => $category,
    'sort_order' => $order,
    ...$extra,
];
$toCompany = ['platform', 'partner', 'group', 'company'];
$toBranch = ['platform', 'partner', 'group', 'company', 'branch'];

return [
    'key' => 'diagnostics',
    'name' => 'diagnostics::module.name',
    'description' => 'diagnostics::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['patients', 'patient_billing'],
    // Any sector may turn it on; the diagnostic and hospital packages (H-5) turn it on by default.
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['diagnostics.view', 'diagnostics.order', 'diagnostics.collect', 'diagnostics.result', 'diagnostics.verify', 'diagnostics.deliver', 'diagnostics.manage'],
    'rules' => [
        $rule('report_turnaround_hours', 'integer', 24, $toBranch, 'reports', 10, ['schema' => ['minimum' => 1, 'maximum' => 720]]),
        // A second person signs results; a parent may lock it on.
        $rule('require_verification', 'boolean', true, $toBranch, 'reports', 20, ['sensitive' => true]),
        $rule('number_prefixes', 'json', ['order' => 'D', 'sample' => 'S'], $toCompany, 'numbering', 30, ['schema' => [
            'type' => 'object',
            'properties' => [
                'order' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{0,6}$'],
                'sample' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{0,6}$'],
            ],
            'additionalProperties' => false,
        ]]),
    ],
    'menu' => [
        [
            'key' => 'diagnostics',
            'label' => 'diagnostics::module.menu',
            'route' => '/diagnostics/orders',
            'icon' => 'scan',
            'order' => 59,
            'section' => 'business',
            'children' => [
                ['key' => 'orders', 'label' => 'diagnostics::module.menu_orders', 'route' => '/diagnostics/orders', 'permission' => 'diagnostics.view'],
                ['key' => 'collect', 'label' => 'diagnostics::module.menu_collect', 'route' => '/diagnostics/orders?stage=ordered', 'permission' => 'diagnostics.collect'],
                ['key' => 'results', 'label' => 'diagnostics::module.menu_results', 'route' => '/diagnostics/orders?stage=collected', 'permission' => 'diagnostics.result'],
                ['key' => 'verify', 'label' => 'diagnostics::module.menu_verify', 'route' => '/diagnostics/orders?stage=resulted', 'permission' => 'diagnostics.verify'],
                ['key' => 'deliver', 'label' => 'diagnostics::module.menu_deliver', 'route' => '/diagnostics/orders?stage=verified', 'permission' => 'diagnostics.deliver'],
                ['key' => 'tests', 'label' => 'diagnostics::module.menu_tests', 'route' => '/diagnostics/tests', 'permission' => 'diagnostics.view'],
                ['key' => 'ranges', 'label' => 'diagnostics::module.menu_ranges', 'route' => '/diagnostics/parameters', 'permission' => 'diagnostics.manage'],
                ['key' => 'referrers', 'label' => 'diagnostics::module.menu_referrers', 'route' => '/diagnostics/referrers', 'permission' => 'diagnostics.view'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'new_order', 'label' => 'diagnostics::module.new_order', 'route' => '/diagnostics/orders?new=1', 'permission' => 'diagnostics.order', 'icon' => 'scan'],
    ],
    // Payloads carry ids only (never names or values).
    'events' => ['diagnostics.ordered', 'diagnostics.sample_collected', 'diagnostics.result_verified', 'diagnostics.report_ready'],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'late', 'label' => 'diagnostics::dashboard.late', 'type' => 'stat', 'provider' => LabWidgets::class, 'permission' => 'diagnostics.view'],
    ]],
    'attention' => [LabWidgets::class],
    'settings' => ['pages' => []],
];
