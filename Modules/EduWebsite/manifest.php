<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('edu_website.*')).
*/

return [
    'key' => 'edu_website',
    'name' => 'edu_website::module.name',
    'description' => 'edu_website::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Programs come from Education; attendance numbers from Attendance and
    // StudentAttendance when they are on (not required).
    'requires' => ['education'],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => [
        // See the website's settings, pages, notices and files.
        'edu_website.view',
        // Change pages, blocks, notices, pictures and files (the draft).
        'edu_website.manage',
        // Start the website, change its address, publish, take it offline.
        'edu_website.publish',
    ],
    'rules' => [
        [
            // Templates offered; a level above turns some off (the allowed list) for everyone below.
            'key' => 'edu_website.templates',
            'type' => 'multi_enum',
            'schema' => ['items' => ['enum' => ['school', 'minimal']], 'minItems' => 1],
            'default' => ['school', 'minimal'],
            'label' => 'edu_website::rules.templates.label',
            'description' => 'edu_website::rules.templates.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'website',
            'sort_order' => 10,
        ],
        [
            'key' => 'edu_website.max_pages',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 200],
            'default' => 12,
            'label' => 'edu_website::rules.max_pages.label',
            'description' => 'edu_website::rules.max_pages.description',
            'overridable_levels' => ['platform', 'partner', 'plan'],
            'category' => 'website',
            'sort_order' => 20,
        ],
        [
            'key' => 'edu_website.max_media_mb',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 50],
            'default' => 5,
            'label' => 'edu_website::rules.max_media_mb.label',
            'description' => 'edu_website::rules.max_media_mb.description',
            'overridable_levels' => ['platform', 'partner', 'plan'],
            'category' => 'website',
            'sort_order' => 30,
        ],
    ],
    // Screens come with WEB-2.
    'menu' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
