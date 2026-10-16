<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\EducationLectures\Dashboard\LectureWidgets;
use Modules\EducationLectures\Dashboard\QuietSubjects;

return [
    'key' => 'education_lectures',
    'name' => 'education_lectures::module.name',
    'description' => 'education_lectures::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Sections, subjects, who teaches what and the students come from Education (its AcademicDirectory).
    'requires' => ['education'],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => ['education_lectures.view', 'education_lectures.post', 'education_lectures.manage'],
    'rules' => [
        [
            'key' => 'education_lectures.approval_required',
            'type' => 'boolean',
            // A teacher's lecture waits for someone who manages lectures before students see it.
            'default' => false,
            'label' => 'education_lectures::rules.approval_required.label',
            'description' => 'education_lectures::rules.approval_required.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'lectures',
            'sort_order' => 10,
        ],
        [
            'key' => 'education_lectures.max_file_kb',
            'type' => 'integer',
            'schema' => ['minimum' => 100, 'maximum' => 51200],
            'default' => 10240,
            'label' => 'education_lectures::rules.max_file_kb.label',
            'description' => 'education_lectures::rules.max_file_kb.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'files',
            'sort_order' => 20,
        ],
        [
            'key' => 'education_lectures.max_files',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 20],
            'default' => 5,
            'label' => 'education_lectures::rules.max_files.label',
            'description' => 'education_lectures::rules.max_files.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'files',
            'sort_order' => 30,
        ],
        [
            'key' => 'education_lectures.video_hosts',
            'type' => 'multi_enum',
            // Where a lecture's video may be (empty: no video links).
            'schema' => ['items' => ['enum' => ['youtube', 'google_drive', 'vimeo', 'facebook']]],
            'default' => ['youtube', 'google_drive'],
            'label' => 'education_lectures::rules.video_hosts.label',
            'description' => 'education_lectures::rules.video_hosts.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'files',
            'sort_order' => 40,
        ],
        [
            'key' => 'education_lectures.notify_on_publish',
            'type' => 'boolean',
            // Students and guardians with a login get a mail when a lecture of their section is published.
            'default' => true,
            'label' => 'education_lectures::rules.notify_on_publish.label',
            'description' => 'education_lectures::rules.notify_on_publish.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'lectures',
            'sort_order' => 50,
        ],
        [
            'key' => 'education_lectures.quiet_days',
            'type' => 'integer',
            // The education home names subjects with no lecture for this many days.
            'schema' => ['minimum' => 1, 'maximum' => 90],
            'default' => 14,
            'label' => 'education_lectures::rules.quiet_days.label',
            'description' => 'education_lectures::rules.quiet_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'lectures',
            'sort_order' => 60,
        ],
    ],
    'menu' => [
        [
            'key' => 'education_lectures',
            'label' => 'education_lectures::module.menu',
            'route' => '/lectures',
            'icon' => 'book',
            'order' => 9,
            'section' => 'education',
            // Its widgets are on the education home; the menu keeps only its settings.
            'dashboard' => false,
            'children' => [
                ['key' => 'lectures', 'label' => 'education_lectures::module.menu_lectures', 'route' => '/lectures', 'permission' => 'education_lectures.view'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'lecture', 'label' => 'education_lectures::module.new_lecture', 'route' => '/lectures?new=1', 'permission' => 'education_lectures.post', 'icon' => 'book'],
    ],
    // Payloads carry ids only.
    'events' => ['education_lectures.published'],
    // Mail only: a lecture a day for a whole section would be many paid SMS.
    'notifications' => [
        'education_lectures.published' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'subject', 'title', 'teacher', 'link'],
            'audience' => 'person',
            'path' => '/portal/lectures',
        ],
    ],
    // A student's (or their child's) lectures from their record in the portal.
    'portal_pages' => [
        ['subject' => 'education.student', 'label' => 'education_lectures::module.portal_page', 'route' => '/portal/lectures/{record}'],
    ],
    'attention' => [LectureWidgets::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'week', 'label' => 'education_lectures::dashboard.week', 'type' => 'stat', 'provider' => LectureWidgets::class, 'permission' => 'education_lectures.view'],
        ['key' => 'quiet', 'label' => 'education_lectures::dashboard.quiet', 'type' => 'list', 'provider' => QuietSubjects::class, 'permission' => 'education_lectures.view'],
    ]],
    'settings' => ['pages' => []],
];
