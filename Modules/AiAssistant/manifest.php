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
        [
            // SC-4: most AI readings a company may make in a calendar month (all modules together);
            // past it, modules go on without AI until the next month.
            'key' => 'ai_assistant.monthly_calls',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 1000000],
            'default' => 2000,
            'label' => 'ai_assistant::rules.monthly_calls.label',
            'description' => 'ai_assistant::rules.monthly_calls.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'usage',
            'sort_order' => 20,
        ],
        [
            // Which model reads (config ai.models): haiku is quick and cheap, sonnet reads harder text.
            'key' => 'ai_assistant.model',
            'type' => 'enum',
            'schema' => ['enum' => ['haiku', 'sonnet']],
            'default' => 'haiku',
            'label' => 'ai_assistant::rules.model.label',
            'description' => 'ai_assistant::rules.model.description',
            'overridable_levels' => ['platform', 'partner', 'plan'],
            'category' => 'usage',
            'sort_order' => 30,
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
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the module's business screens (its rules show already).
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
