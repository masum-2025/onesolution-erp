<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'attendance',
    'name' => 'attendance::module.name',
    'description' => 'attendance::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['attendance.view', 'attendance.manage'],
    'rules' => [
        [
            'key' => 'attendance.late_grace_minutes',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 240],
            'default' => 10,
            'label' => 'attendance::rules.late_grace_minutes.label',
            'description' => 'attendance::rules.late_grace_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch', 'department'],
            'category' => 'lateness',
            'sort_order' => 10,
        ],
        [
            'key' => 'attendance.weekend_days',
            'type' => 'multi_enum',
            'schema' => ['items' => ['enum' => ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']], 'maxItems' => 3],
            // Country values (e.g. BD) are data, seeded per country.
            'default' => ['sat', 'sun'],
            'label' => 'attendance::rules.weekend_days.label',
            'description' => 'attendance::rules.weekend_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'calendar',
            'sort_order' => 20,
        ],
        [
            'key' => 'attendance.half_day_after_minutes',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 720],
            'default' => 240,
            'label' => 'attendance::rules.half_day_after_minutes.label',
            'description' => 'attendance::rules.half_day_after_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch', 'department'],
            'category' => 'lateness',
            'sort_order' => 30,
        ],
        [
            'key' => 'attendance.geo_fence_required',
            'type' => 'boolean',
            'default' => false,
            'label' => 'attendance::rules.geo_fence_required.label',
            'description' => 'attendance::rules.geo_fence_required.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch', 'department'],
            'category' => 'check_in',
            'sort_order' => 40,
        ],
    ],
    'menu' => [
        [
            'key' => 'attendance',
            'label' => 'attendance::module.menu',
            'route' => '/attendance',
            'icon' => 'clock',
            'order' => 20,
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
