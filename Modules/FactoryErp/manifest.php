<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'factory_erp',
    'name' => 'factory_erp::module.name',
    'description' => 'factory_erp::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => ['inventory', 'accounting'],
    'sectors' => ['factory'],
    'plans' => ['*'],
    'permissions' => ['factory_erp.view', 'factory_erp.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'factory_erp',
            'label' => 'factory_erp::module.menu',
            'route' => '/factory_erp',
            'icon' => 'factory',
            'order' => 70,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
