<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'attendance',
    'name' => 'attendance::module.name',
    'description' => 'attendance::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['attendance.view', 'attendance.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'attendance',
            'label' => 'attendance::module.menu',
            'route' => '/attendance',
            'icon' => 'clock',
            'order' => 20,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
