<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'hrm',
    'name' => 'hrm::module.name',
    'description' => 'hrm::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['hrm.view', 'hrm.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'hrm',
            'label' => 'hrm::module.menu',
            'route' => '/hrm',
            'icon' => 'users',
            'order' => 10,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
