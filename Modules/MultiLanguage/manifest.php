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
    'menu' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
