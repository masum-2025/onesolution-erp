<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'carbon_management',
    'name' => 'carbon_management::module.name',
    'description' => 'carbon_management::module.description',
    'version' => '0.1.0',
    'category' => 'sustainability',
    'requires' => ['energy_monitoring'],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['carbon_management.view', 'carbon_management.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'carbon_management',
            'label' => 'carbon_management::module.menu',
            'route' => '/carbon_management',
            'icon' => 'leaf',
            'order' => 310,
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
