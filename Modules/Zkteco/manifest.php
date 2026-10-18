<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

return [
    'key' => 'zkteco',
    'name' => 'zkteco::module.name',
    'description' => 'zkteco::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Employees are HRM's; punches go to staff attendance through its DeviceAttendance contract.
    'requires' => ['hrm', 'attendance'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        'zkteco.view',
        // Add, change and try terminals, pull them now, match their users to employees.
        'zkteco.manage',
    ],
    'rules' => [
        [
            'key' => 'zkteco.pull_minutes',
            'type' => 'integer',
            // How often each terminal is read (the scheduler looks every minute).
            'schema' => ['minimum' => 1, 'maximum' => 1440],
            'default' => 5,
            'label' => 'zkteco::rules.pull_minutes.label',
            'description' => 'zkteco::rules.pull_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'devices',
            'sort_order' => 10,
        ],
    ],
    'menu' => [
        [
            'key' => 'zkteco',
            'label' => 'zkteco::module.menu',
            'route' => '/zkteco',
            'icon' => 'clock',
            'order' => 21,
            'section' => 'people',
            'children' => [
                ['key' => 'devices', 'label' => 'zkteco::module.menu_devices', 'route' => '/zkteco', 'permission' => 'zkteco.view'],
            ],
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
