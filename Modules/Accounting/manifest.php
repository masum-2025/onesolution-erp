<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'accounting',
    'name' => 'accounting::module.name',
    'description' => 'accounting::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['accounting.view', 'accounting.post', 'accounting.approve'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'accounting',
            'label' => 'accounting::module.menu',
            'route' => '/accounting',
            'icon' => 'book',
            'order' => 40,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
