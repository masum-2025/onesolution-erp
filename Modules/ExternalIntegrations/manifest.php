<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'external_integrations',
    'name' => 'external_integrations::module.name',
    'description' => 'external_integrations::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => ['api_integration'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['external_integrations.manage'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'external_integrations',
            'label' => 'external_integrations::module.menu',
            'route' => '/external_integrations',
            'icon' => 'link',
            'order' => 140,
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
