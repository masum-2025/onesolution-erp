<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\StudentAttendance\Dashboard\AttendanceWidgets;
use Modules\StudentAttendance\Dashboard\LowAttendance;
use Modules\StudentAttendance\Offline\AttendanceSync;

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
        [
            'key' => 'student_attendance.notify_absent',
            'type' => 'json',
            // Off by default (SMS costs money). Families of a student absent today are told, after delay_minutes so a slip can be put right.
            'schema' => [
                'type' => 'object', 'required' => ['enabled', 'channels'], 'additionalProperties' => false,
                'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'channels' => ['type' => 'array', 'items' => ['enum' => ['sms', 'mail']], 'minItems' => 1, 'uniqueItems' => true],
                    'delay_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 240],
                ],
            ],
            'default' => ['enabled' => false, 'channels' => ['sms'], 'delay_minutes' => 30],
            'label' => 'student_attendance::rules.notify_absent.label',
            'description' => 'student_attendance::rules.notify_absent.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'families',
            'sort_order' => 60,
        ],
        [
            'key' => 'student_attendance.notify_class_skip',
            'type' => 'boolean',
            // By period, with absence messages on: also tell when a student was in earlier but misses a later period.
            'default' => false,
            'label' => 'student_attendance::rules.notify_class_skip.label',
            'description' => 'student_attendance::rules.notify_class_skip.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'families',
            'sort_order' => 70,
        ],
        [
            'key' => 'student_attendance.low_warn_bp',
            'type' => 'integer',
            // Students present on fewer than this share of their last 30 days' school days show on the home and in the bell (7500 = 75%).
            'schema' => ['minimum' => 1, 'maximum' => 10000],
            'default' => 7500,
            'label' => 'student_attendance::rules.low_warn_bp.label',
            'description' => 'student_attendance::rules.low_warn_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'counting',
            'sort_order' => 35,
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
                ['key' => 'register', 'label' => 'student_attendance::module.menu_register', 'route' => '/student-attendance/register', 'permission' => 'student_attendance.view'],
                ['key' => 'periods', 'label' => 'student_attendance::module.menu_periods', 'route' => '/student-attendance/periods', 'permission' => 'student_attendance.manage'],
                ['key' => 'holidays', 'label' => 'student_attendance::module.menu_holidays', 'route' => '/student-attendance/holidays', 'permission' => 'student_attendance.view'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'take', 'label' => 'student_attendance::module.take', 'route' => '/student-attendance', 'permission' => 'student_attendance.take', 'icon' => 'clipboard'],
    ],
    // Teachers take attendance on a phone without a network; it is sent when back online.
    'sync_records' => [AttendanceSync::class],
    // Payloads carry ids only.
    'events' => ['student_attendance.sheet_saved'],
    // To families (wording in student_attendance::notifications); the link opens the portal.
    'notifications' => [
        'student_attendance.absent' => ['channels' => ['mail', 'sms'], 'placeholders' => ['product', 'organization', 'student', 'date', 'link'], 'audience' => 'person', 'path' => '/portal'],
        'student_attendance.skipped' => ['channels' => ['mail', 'sms'], 'placeholders' => ['product', 'organization', 'student', 'date', 'period', 'subject', 'link'], 'audience' => 'person', 'path' => '/portal'],
    ],
    // A family opens the student's attendance from the student's record in the portal.
    'portal_pages' => [
        ['subject' => 'education.student', 'label' => 'student_attendance::module.portal_page', 'route' => '/portal/student-attendance/{record}'],
    ],
    'attention' => [AttendanceWidgets::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'today', 'label' => 'student_attendance::dashboard.today', 'type' => 'stat', 'provider' => AttendanceWidgets::class, 'permission' => 'student_attendance.view'],
        ['key' => 'low', 'label' => 'student_attendance::dashboard.low', 'type' => 'list', 'provider' => LowAttendance::class, 'permission' => 'student_attendance.view'],
    ]],
    'settings' => ['pages' => []],
];
