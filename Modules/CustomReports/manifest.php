<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'custom_reports',
    'name' => 'custom_reports::module.name',
    'description' => 'custom_reports::module.description',
    'version' => '0.1.0',
    'category' => 'governance',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['custom_reports.view', 'custom_reports.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'custom_reports',
            'label' => 'custom_reports::module.menu',
            'route' => '/custom_reports',
            'icon' => 'chart',
            'order' => 410,
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
