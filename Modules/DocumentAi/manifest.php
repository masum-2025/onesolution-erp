<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'document_ai',
    'name' => 'document_ai::module.name',
    'description' => 'document_ai::module.description',
    'version' => '0.1.0',
    'category' => 'ai',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['document_ai.use', 'document_ai.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'document_ai',
            'label' => 'document_ai::module.menu',
            'route' => '/document_ai',
            'icon' => 'scan',
            'order' => 200,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => true,
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the module's business screens (its rules show already).
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
