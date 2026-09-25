<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'crm',
    'name' => 'crm::module.name',
    'description' => 'crm::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['crm.view', 'crm.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'crm',
            'label' => 'crm::module.menu',
            'route' => '/crm',
            'icon' => 'handshake',
            'order' => 60,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
