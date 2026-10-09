<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'multi_language',
    'name' => 'multi_language::module.name',
    'description' => 'multi_language::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['multi_language.manage'],
    'rules' => [],
    // A group's or company's own wording and its languages (LANG-1); the languages themselves are the platform's.
    'menu' => [
        [
            'key' => 'multi_language',
            'label' => 'multi_language::module.menu',
            'route' => '/languages',
            'icon' => 'languages',
            'order' => 90,
            'permission' => 'multi_language.manage',
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the module's business screens (its rules show already).
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => [
        ['key' => 'wording', 'label' => 'multi_language::module.menu', 'route' => '/languages', 'permission' => 'multi_language.manage'],
    ]],
];
