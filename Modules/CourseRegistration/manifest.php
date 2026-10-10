<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

return [
    'key' => 'course_registration',
    'name' => 'course_registration::module.name',
    'description' => 'course_registration::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Students, sessions, subjects and curricula come from Education (its AcademicDirectory).
    'requires' => ['education'],
    'sectors' => ['university', 'college', 'school', 'madrasa'],
    'plans' => ['*'],
    'permissions' => [
        'course_registration.view', 'course_registration.manage', 'course_registration.register',
        'course_registration.approve', 'course_registration.record_outcome',
    ],
    'rules' => [
        [
            'key' => 'course_registration.min_credits',
            'type' => 'integer',
            // Whole credits a student registers at least in a session (0: no lower limit).
            'schema' => ['minimum' => 0, 'maximum' => 60],
            'default' => 0,
            'label' => 'course_registration::rules.min_credits.label',
            'description' => 'course_registration::rules.min_credits.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'credits',
            'sort_order' => 10,
        ],
        [
            'key' => 'course_registration.max_credits',
            'type' => 'integer',
            // Whole credits a student may register in a session without an overload (0: no upper limit).
            'schema' => ['minimum' => 0, 'maximum' => 60],
            'default' => 0,
            'label' => 'course_registration::rules.max_credits.label',
            'description' => 'course_registration::rules.max_credits.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'credits',
            'sort_order' => 20,
        ],
        [
            'key' => 'course_registration.overload_credits',
            'type' => 'integer',
            // Whole credits above the maximum allowed with the advisor's approval.
            'schema' => ['minimum' => 0, 'maximum' => 30],
            'default' => 3,
            'label' => 'course_registration::rules.overload_credits.label',
            'description' => 'course_registration::rules.overload_credits.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'credits',
            'sort_order' => 30,
        ],
        [
            'key' => 'course_registration.approval_required',
            'type' => 'boolean',
            'default' => true,
            'label' => 'course_registration::rules.approval_required.label',
            'description' => 'course_registration::rules.approval_required.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'registration',
            'sort_order' => 40,
        ],
        [
            'key' => 'course_registration.prerequisites_enforced',
            'type' => 'boolean',
            'default' => true,
            'label' => 'course_registration::rules.prerequisites_enforced.label',
            'description' => 'course_registration::rules.prerequisites_enforced.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'registration',
            'sort_order' => 50,
        ],
        [
            'key' => 'course_registration.waitlist',
            'type' => 'boolean',
            'default' => true,
            'label' => 'course_registration::rules.waitlist.label',
            'description' => 'course_registration::rules.waitlist.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'registration',
            'sort_order' => 60,
        ],
        [
            'key' => 'course_registration.self_registration',
            'type' => 'boolean',
            'default' => false,
            'label' => 'course_registration::rules.self_registration.label',
            'description' => 'course_registration::rules.self_registration.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'registration',
            'sort_order' => 70,
        ],
    ],
    'menu' => [
        [
            'key' => 'course_registration',
            'label' => 'course_registration::module.menu',
            'route' => '/course-registration',
            'icon' => 'clipboard',
            'order' => 41,
            'section' => 'business',
            'children' => [
                ['key' => 'offerings', 'label' => 'course_registration::module.menu_offerings', 'route' => '/course-registration', 'permission' => 'course_registration.view'],
                ['key' => 'registrations', 'label' => 'course_registration::module.menu_registrations', 'route' => '/course-registration/registrations', 'permission' => 'course_registration.view'],
            ],
        ],
    ],
    // Fees (per credit) and exams (rosters) listen to these; payloads carry ids only.
    'events' => ['course_registration.course_registered', 'course_registration.course_dropped', 'course_registration.registration_approved', 'course_registration.seat_offered'],
    // A student who moved up from a waiting list (wording in course_registration::notifications).
    'notifications' => [
        'course_registration.seat_offered' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'subject', 'session', 'link'],
            'audience' => 'person',
            'path' => '/portal',
        ],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
