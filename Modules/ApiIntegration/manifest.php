<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'api_integration',
    'name' => 'api_integration::module.name',
    'description' => 'api_integration::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['api_integration.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'api_integration',
            'label' => 'api_integration::module.menu',
            'route' => '/api_integration',
            'icon' => 'plug',
            'order' => 130,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
