<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('attendance.*')).
*/

use Modules\Attendance\Dashboard\CheckedInToday;
use Modules\Attendance\Dashboard\CorrectionsWaiting;

return [
    'key' => 'attendance',
    'name' => 'attendance::module.name',
    'description' => 'attendance::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Employees are HRM's (read through Modules\Hrm\Directory\EmployeeDirectory).
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        'attendance.view',
        // Shifts, holidays, rosters; punches written or voided by hand; corrections for someone.
        'attendance.manage',
        // Check in and out for oneself, and ask to fix one's own day.
        'attendance.punch',
        // Approve or reject corrections (never one's own).
        'attendance.correct',
    ],
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
            'key' => 'attendance.overtime_min_minutes',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 240],
            'default' => 30,
            'label' => 'attendance::rules.overtime_min_minutes.label',
            'description' => 'attendance::rules.overtime_min_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch', 'department'],
            'country_specific' => true,
            'category' => 'lateness',
            'sort_order' => 35,
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
        [
            'key' => 'attendance.self_punch',
            'type' => 'boolean',
            'default' => true,
            'label' => 'attendance::rules.self_punch.label',
            'description' => 'attendance::rules.self_punch.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch', 'department'],
            'category' => 'check_in',
            'sort_order' => 45,
        ],
        [
            'key' => 'attendance.early_punch_minutes',
            'type' => 'integer',
            'schema' => ['minimum' => 30, 'maximum' => 600],
            'default' => 240,
            'label' => 'attendance::rules.early_punch_minutes.label',
            'description' => 'attendance::rules.early_punch_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'check_in',
            'sort_order' => 50,
        ],
        [
            'key' => 'attendance.correction_max_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 366],
            'default' => 31,
            'label' => 'attendance::rules.correction_max_days.label',
            'description' => 'attendance::rules.correction_max_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'corrections',
            'sort_order' => 60,
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
    // Screens (and their settings pages) come with ATT-2.
    'dashboard' => ['widgets' => [
        ['key' => 'checked_in', 'label' => 'attendance::dashboard.checked_in', 'type' => 'stat', 'provider' => CheckedInToday::class, 'permission' => 'attendance.view', 'overview' => true],
        ['key' => 'corrections', 'label' => 'attendance::dashboard.corrections', 'type' => 'stat', 'provider' => CorrectionsWaiting::class, 'permission' => 'attendance.view'],
    ]],
    'attention' => [CorrectionsWaiting::class],
    'settings' => ['pages' => []],
];
