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
    'rules' => [],
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
