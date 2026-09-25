<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'inventory',
    'name' => 'inventory::module.name',
    'description' => 'inventory::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['inventory.view', 'inventory.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'inventory',
            'label' => 'inventory::module.menu',
            'route' => '/inventory',
            'icon' => 'package',
            'order' => 50,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
