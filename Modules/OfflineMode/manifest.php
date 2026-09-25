<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'offline_mode',
    'name' => 'offline_mode::module.name',
    'description' => 'offline_mode::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['offline_mode.manage'],
    'rules' => [],
    'menu' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
