<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'multi_currency',
    'name' => 'multi_currency::module.name',
    'description' => 'multi_currency::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => ['accounting'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['multi_currency.manage'],
    'rules' => [],
    'menu' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the module's business screens (its rules show already).
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
