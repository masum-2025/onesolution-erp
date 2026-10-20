<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('edu_website.*')).
*/

use Modules\EduWebsite\Dashboard\NoticesThisMonth;
use Modules\EduWebsite\Dashboard\UnpublishedChanges;
use Modules\EduWebsite\Services\Sites;

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
            // Templates offered (school, minimal, premium: Sites::TEMPLATES); a level above turns some off
            // (the allowed list) for everyone below. Manifests are cached by file time: change this file with the list.
            'key' => 'edu_website.templates',
            'type' => 'multi_enum',
            'schema' => ['items' => ['enum' => Sites::TEMPLATES], 'minItems' => 1],
            'default' => Sites::TEMPLATES,
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
        [
            // The website on the school's own domain (www.abcschool.edu.bd), proven by DNS; per plan or partner.
            'key' => 'edu_website.own_domain',
            'type' => 'boolean',
            'default' => false,
            'label' => 'edu_website::rules.own_domain.label',
            'description' => 'edu_website::rules.own_domain.description',
            'overridable_levels' => ['platform', 'partner', 'plan'],
            'category' => 'website',
            'sort_order' => 40,
        ],
    ],
    'menu' => [
        [
            'key' => 'website',
            'label' => 'edu_website::module.menu',
            'route' => '/website',
            'icon' => 'globe',
            'order' => 40,
            'section' => 'education',
            // Its home is the "Overview" line (the site at a glance); the notices count shows on the main dashboard.
            'dashboard' => false,
            'children' => [
                ['key' => 'overview', 'label' => 'edu_website::module.menu_overview', 'route' => '/website', 'permission' => 'edu_website.view'],
                ['key' => 'pages', 'label' => 'edu_website::module.menu_pages', 'route' => '/website/pages', 'permission' => 'edu_website.view'],
                ['key' => 'notices', 'label' => 'edu_website::module.menu_notices', 'route' => '/website/notices', 'permission' => 'edu_website.view'],
                ['key' => 'media', 'label' => 'edu_website::module.menu_media', 'route' => '/website/media', 'permission' => 'edu_website.view'],
            ],
        ],
    ],
    // The header "New" menu: a notice on the website from anywhere.
    'quick_actions' => [
        ['key' => 'notice', 'label' => 'edu_website::module.new_notice', 'route' => '/website/notices?new=1', 'permission' => 'edu_website.manage', 'icon' => 'globe'],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'notices', 'label' => 'edu_website::dashboard.notices', 'type' => 'stat', 'provider' => NoticesThisMonth::class, 'permission' => 'edu_website.view', 'overview' => true],
    ]],
    'attention' => [UnpublishedChanges::class],
    'settings' => ['pages' => [
        ['key' => 'settings', 'label' => 'edu_website::module.menu_settings', 'route' => '/website/settings', 'permission' => 'edu_website.view'],
    ]],
];
