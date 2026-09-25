<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'advanced_audit',
    'name' => 'advanced_audit::module.name',
    'description' => 'advanced_audit::module.description',
    'version' => '0.1.0',
    'category' => 'governance',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['advanced_audit.view', 'advanced_audit.export'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'advanced_audit',
            'label' => 'advanced_audit::module.menu',
            'route' => '/advanced_audit',
            'icon' => 'shield',
            'order' => 400,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
