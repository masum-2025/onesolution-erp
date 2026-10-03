<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'energy_monitoring',
    'name' => 'energy_monitoring::module.name',
    'description' => 'energy_monitoring::module.description',
    'version' => '0.1.0',
    'category' => 'sustainability',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['energy_monitoring.view', 'energy_monitoring.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'energy_monitoring',
            'label' => 'energy_monitoring::module.menu',
            'route' => '/energy_monitoring',
            'icon' => 'zap',
            'order' => 300,
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
