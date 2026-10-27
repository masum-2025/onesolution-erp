<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('recruitment.*')).
*/

use Modules\Recruitment\Dashboard\FeedbackOwed;
use Modules\Recruitment\Dashboard\NewApplications;
use Modules\Recruitment\Dashboard\OpenJobs;

$integer = fn (string $key, int $default, int $min, int $max, array $levels, int $order) => [
    'key' => "recruitment.{$key}",
    'type' => 'integer',
    'schema' => ['minimum' => $min, 'maximum' => $max],
    'default' => $default,
    'label' => "recruitment::rules.{$key}.label",
    'description' => "recruitment::rules.{$key}.description",
    'overridable_levels' => $levels,
    'category' => 'recruitment',
    'sort_order' => $order,
];

return [
    'key' => 'recruitment',
    'name' => 'recruitment::module.name',
    'description' => 'recruitment::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Units, positions and hiring are HRM's (EmployeeDirectory, EmployeeLifecycle).
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // See the openings and applications of a unit and below.
        'recruitment.view',
        // Openings, moving and rejecting applications, interviews, the careers page address.
        'recruitment.manage',
        // Interview candidates one is asked to: their application, CV and one's own feedback.
        'recruitment.interview',
        // Hire a candidate into HRM (also needs hrm.manage there).
        'recruitment.hire',
    ],
    'rules' => [
        [
            // The steps an application goes through, in order; the first is where new ones start.
            'key' => 'recruitment.stages',
            'type' => 'json',
            'schema' => [
                'type' => 'array', 'minItems' => 2, 'maxItems' => 10,
                'items' => [
                    'type' => 'object', 'required' => ['key', 'name'], 'additionalProperties' => false,
                    'properties' => [
                        'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,29}$'],
                        'name' => ['type' => 'object'],
                    ],
                ],
            ],
            'default' => [
                ['key' => 'applied', 'name' => ['en' => 'Applied', 'bn' => 'আবেদন']],
                ['key' => 'screening', 'name' => ['en' => 'Screening', 'bn' => 'বাছাই']],
                ['key' => 'interview', 'name' => ['en' => 'Interview', 'bn' => 'সাক্ষাৎকার']],
                ['key' => 'offer', 'name' => ['en' => 'Offer', 'bn' => 'প্রস্তাব']],
            ],
            'label' => 'recruitment::rules.stages.label',
            'description' => 'recruitment::rules.stages.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'recruitment',
            'sort_order' => 10,
        ],
        [
            'key' => 'recruitment.public_apply',
            'type' => 'boolean',
            'default' => true,
            'label' => 'recruitment::rules.public_apply.label',
            'description' => 'recruitment::rules.public_apply.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'recruitment',
            'sort_order' => 20,
        ],
        $integer('cv_max_kb', 5120, 100, 20480, ['platform', 'partner', 'plan'], 30),
        $integer('applications_per_email_day', 3, 1, 20, ['platform', 'partner', 'plan'], 40),
        // Privacy: people not taken are forgotten after this many days.
        $integer('keep_rejected_days', 180, 30, 1095, ['platform', 'partner', 'plan', 'group', 'company'], 50),
    ],
    'menu' => [
        [
            'key' => 'recruitment',
            'label' => 'recruitment::module.menu',
            'route' => '/recruitment/openings',
            'icon' => 'briefcase',
            'order' => 24,
            'section' => 'people',
            'children' => [
                ['key' => 'openings', 'label' => 'recruitment::module.menu_openings', 'route' => '/recruitment/openings', 'permission' => 'recruitment.view'],
                ['key' => 'interviews', 'label' => 'recruitment::module.menu_interviews', 'route' => '/recruitment/interviews', 'permission' => 'recruitment.interview'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'opening', 'label' => 'recruitment::module.new_opening', 'route' => '/recruitment/openings?new=1', 'permission' => 'recruitment.manage', 'icon' => 'briefcase'],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'open', 'label' => 'recruitment::dashboard.open', 'type' => 'stat', 'provider' => OpenJobs::class, 'permission' => 'recruitment.view', 'overview' => true],
        ['key' => 'new', 'label' => 'recruitment::dashboard.new', 'type' => 'stat', 'provider' => NewApplications::class, 'permission' => 'recruitment.manage'],
    ]],
    'attention' => [NewApplications::class, FeedbackOwed::class],
    'notifications' => [
        // To the people who handle the opening: no candidate's name or contact in the mail.
        'recruitment.applied' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'job', 'link'],
            'audience' => 'client',
            'path' => '/recruitment/openings',
        ],
        // To the candidate: confirm the email address before the application counts.
        'recruitment.confirm' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'name', 'job', 'hours', 'link'],
            'audience' => 'person',
            'path' => '/',
        ],
        // To the interviewers.
        'recruitment.interview_scheduled' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'job', 'when', 'mode', 'place', 'link'],
            'audience' => 'client',
            'path' => '/recruitment/interviews',
        ],
        // To the candidate.
        'recruitment.interview_invite' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'name', 'job', 'when', 'mode', 'place', 'link'],
            'audience' => 'person',
            'path' => '/',
        ],
        'recruitment.not_selected' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'name', 'job', 'link'],
            'audience' => 'person',
            'path' => '/',
        ],
    ],
    'settings' => ['pages' => [
        ['key' => 'careers', 'label' => 'recruitment::module.menu_settings', 'route' => '/recruitment/settings', 'permission' => 'recruitment.manage'],
    ]],
];
