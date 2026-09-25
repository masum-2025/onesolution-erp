<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'payroll',
    'name' => 'payroll::module.name',
    'description' => 'payroll::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => ['hrm', 'attendance'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['payroll.view', 'payroll.run', 'payroll.approve'],
    'rules' => [],
    'menu' => [
        [
            'key' => 'payroll',
            'label' => 'payroll::module.menu',
            'route' => '/payroll',
            'icon' => 'banknote',
            'order' => 30,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
