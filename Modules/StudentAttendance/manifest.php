<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

return [
    'key' => 'student_attendance',
    'name' => 'student_attendance::module.name',
    'description' => 'student_attendance::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Sections, students, class and subject teachers come from Education (its AcademicDirectory).
    'requires' => ['education'],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => [
        'student_attendance.view',
        // Take attendance of one's own section (class teacher) or own subject's period (subject teacher).
        'student_attendance.take',
        // Any section, any past day of the session, periods and holidays.
        'student_attendance.manage',
    ],
    'rules' => [
        [
            'key' => 'student_attendance.by_period',
            'type' => 'boolean',
            // Off: once a day by the class teacher. On: each period by the subject's teacher.
            'default' => false,
            'label' => 'student_attendance::rules.by_period.label',
            'description' => 'student_attendance::rules.by_period.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'taking',
            'sort_order' => 10,
        ],
        [
            'key' => 'student_attendance.period_present_share_bp',
            'type' => 'integer',
            // By period: a day counts as present when the student was present in at least this share of its periods (5000 = half).
            'schema' => ['minimum' => 1, 'maximum' => 10000],
            'default' => 5000,
            'label' => 'student_attendance::rules.period_present_share_bp.label',
            'description' => 'student_attendance::rules.period_present_share_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'counting',
            'sort_order' => 20,
        ],
        [
            'key' => 'student_attendance.late_counts_present',
            'type' => 'boolean',
            'default' => true,
            'label' => 'student_attendance::rules.late_counts_present.label',
            'description' => 'student_attendance::rules.late_counts_present.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'counting',
            'sort_order' => 30,
        ],
        [
            'key' => 'student_attendance.edit_days',
            'type' => 'integer',
            // How many days back a teacher may take or change attendance (0: today only); managers any day of the session.
            'schema' => ['minimum' => 0, 'maximum' => 60],
            'default' => 2,
            'label' => 'student_attendance::rules.edit_days.label',
            'description' => 'student_attendance::rules.edit_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'taking',
            'sort_order' => 40,
        ],
        [
            'key' => 'student_attendance.weekend_days',
            'type' => 'multi_enum',
            'schema' => ['items' => ['enum' => ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']], 'maxItems' => 3],
            // Schools' weekly days off (country values in database/seeders/data/rule-values.php).
            'default' => ['sat', 'sun'],
            'label' => 'student_attendance::rules.weekend_days.label',
            'description' => 'student_attendance::rules.weekend_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'calendar',
            'sort_order' => 50,
        ],
    ],
    'menu' => [
        [
            'key' => 'student_attendance',
            'label' => 'student_attendance::module.menu',
            'route' => '/student-attendance',
            'icon' => 'clipboard',
            'order' => 6,
            'section' => 'education',
            'dashboard' => false,
            'children' => [
                ['key' => 'today', 'label' => 'student_attendance::module.menu_today', 'route' => '/student-attendance', 'permission' => 'student_attendance.view'],
            ],
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
