<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Made by make:erp-module; business numbers live in rules, never here.
*/

return [
    'key' => 'fleet',
    'name' => 'fleet::module.name',
    'description' => 'fleet::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['fleet.view', 'fleet.manage'],
    'rules' => [
        [
            'key' => 'fleet.service_every_km',
            'type' => 'integer',
            'schema' => ['minimum' => 500, 'maximum' => 100000],
            'default' => 5000,
            'label' => 'fleet::rules.service_every_km.label',
            'description' => 'fleet::rules.service_every_km.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'general',
            'sort_order' => 10,
        ],
    ],
    'menu' => [
        [
            'key' => 'fleet',
            'label' => 'fleet::module.menu',
            'route' => '/fleet/vehicles',
            'icon' => 'package',
            'order' => 90,
            'children' => [
                ['key' => 'vehicles', 'label' => 'fleet::module.menu_vehicles', 'route' => '/fleet/vehicles', 'permission' => 'fleet.view'],
                ['key' => 'trips', 'label' => 'fleet::module.menu_trips', 'route' => '/fleet/trips', 'permission' => 'fleet.view'],
            ],
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
