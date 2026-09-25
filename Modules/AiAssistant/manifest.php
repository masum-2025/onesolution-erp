<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'ai_assistant',
    'name' => 'ai_assistant::module.name',
    'description' => 'ai_assistant::module.description',
    'version' => '0.1.0',
    'category' => 'ai',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['ai_assistant.use', 'ai_assistant.manage'],
    'rules' => [
        [
            'key' => 'ai_assistant.data_scope',
            'type' => 'enum',
            'schema' => ['enum' => ['own_records', 'branch', 'company']],
            'default' => 'own_records',
            'label' => 'ai_assistant::rules.data_scope.label',
            'description' => 'ai_assistant::rules.data_scope.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'privacy',
            'sort_order' => 10,
        ],
    ],
    'menu' => [
        [
            'key' => 'ai_assistant',
            'label' => 'ai_assistant::module.menu',
            'route' => '/ai_assistant',
            'icon' => 'sparkles',
            'order' => 210,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => true,
];
