<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('recruitment.*')).
*/

use Modules\Recruitment\Dashboard\FeedbackOwed;
use Modules\Recruitment\Dashboard\NewApplications;
use Modules\Recruitment\Dashboard\OffersToApprove;
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
        [
            // A salary offered is money: someone else who may hire approves it before it goes.
            'key' => 'recruitment.offer_needs_approval',
            'type' => 'boolean',
            'default' => true,
            'label' => 'recruitment::rules.offer_needs_approval.label',
            'description' => 'recruitment::rules.offer_needs_approval.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'recruitment',
            'sort_order' => 60,
        ],
        [
            // The offer letter until a company writes its own (per country or partner), per language, with {placeholders}.
            'key' => 'recruitment.offer_template',
            'type' => 'json',
            'schema' => ['type' => 'object', 'required' => ['en']],
            'default' => [
                'en' => '{today}

Dear {candidate.name},

We are pleased to offer you the position of {job.title} at {company.name} ({unit.name}).

Your salary will be {salary} a month, and your first day will be {start_date}.

Please let us know your answer by {respond_by}.

We look forward to working with you.

{company.name}',
                'bn' => '{today}

প্রিয় {candidate.name},

আমরা আনন্দের সাথে আপনাকে {company.name}-এ ({unit.name}) {job.title} পদে নিয়োগের প্রস্তাব দিচ্ছি।

আপনার মাসিক বেতন হবে {salary}, আর প্রথম কর্মদিবস {start_date}।

{respond_by}-এর মধ্যে আপনার উত্তর জানান।

আপনার সাথে কাজ করার অপেক্ষায় রইলাম।

{company.name}',
            ],
            'label' => 'recruitment::rules.offer_template.label',
            'description' => 'recruitment::rules.offer_template.description',
            'overridable_levels' => ['platform', 'partner', 'plan'],
            'category' => 'recruitment',
            'sort_order' => 70,
        ],
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
    'attention' => [NewApplications::class, FeedbackOwed::class, OffersToApprove::class],
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
        // To people who may hire: an offer waits for their approval (no name or salary in the mail).
        'recruitment.offer_to_approve' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'job', 'link'],
            'audience' => 'client',
            'path' => '/recruitment/openings',
        ],
        // To the candidate: the offer and the link to answer it.
        'recruitment.offer_sent' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'name', 'job', 'respond_by', 'link'],
            'audience' => 'person',
            'path' => '/',
        ],
        // To the people who wrote and approved it and manage recruitment: the candidate answered.
        'recruitment.offer_answered' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'job', 'answer', 'link'],
            'audience' => 'client',
            'path' => '/recruitment/openings',
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
        ['key' => 'letter', 'label' => 'recruitment::module.menu_letter', 'route' => '/recruitment/letter', 'permission' => 'recruitment.manage'],
    ]],
];
