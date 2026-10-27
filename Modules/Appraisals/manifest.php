<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('appraisals.*')).
*/

use Modules\Appraisals\Dashboard\MyAppraisal;
use Modules\Appraisals\Dashboard\ReviewsInProgress;
use Modules\Appraisals\Dashboard\TeamAppraisals;

$rule = fn (string $key, string $type, mixed $default, array $levels, int $order, array $schema = []) => [
    'key' => "appraisals.{$key}",
    'type' => $type,
    ...($schema === [] ? [] : ['schema' => $schema]),
    'default' => $default,
    'label' => "appraisals::rules.{$key}.label",
    'description' => "appraisals::rules.{$key}.description",
    'overridable_levels' => $levels,
    'category' => 'appraisals',
    'sort_order' => $order,
];
$all = ['platform', 'partner', 'plan', 'group', 'company'];
$names = ['type' => 'object'];

return [
    'key' => 'appraisals',
    'name' => 'appraisals::module.name',
    'description' => 'appraisals::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Employees, their managers and units are HRM's (EmployeeDirectory).
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // See the appraisals of a unit and below (read only).
        'appraisals.view',
        // Cycles, competencies, sending a review back a step; see every review of the unit.
        'appraisals.manage',
        // Review the people one manages (HRM's manager), never oneself.
        'appraisals.review',
        // Write one's own goals, review oneself, acknowledge the result.
        'appraisals.self',
    ],
    'rules' => [
        // The rating scale, lowest first (2 to 10 steps).
        $rule('rating_scale', 'json', [
            ['value' => 1, 'name' => ['en' => 'Below expectations', 'bn' => 'প্রত্যাশার নিচে']],
            ['value' => 2, 'name' => ['en' => 'Needs improvement', 'bn' => 'উন্নতি দরকার']],
            ['value' => 3, 'name' => ['en' => 'Meets expectations', 'bn' => 'প্রত্যাশা পূরণ']],
            ['value' => 4, 'name' => ['en' => 'Exceeds expectations', 'bn' => 'প্রত্যাশার বেশি']],
            ['value' => 5, 'name' => ['en' => 'Outstanding', 'bn' => 'অসাধারণ']],
        ], $all, 10, ['type' => 'array', 'minItems' => 2, 'maxItems' => 10, 'items' => ['type' => 'object', 'required' => ['value', 'name'],
            'properties' => ['value' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'name' => $names]]]),
        // Result bands by the overall score (per mille, 0-1000), highest first: a score gets the first band it reaches.
        $rule('bands', 'json', [
            ['min' => 900, 'key' => 'outstanding', 'name' => ['en' => 'Outstanding', 'bn' => 'অসাধারণ']],
            ['min' => 700, 'key' => 'strong', 'name' => ['en' => 'Strong', 'bn' => 'ভালো']],
            ['min' => 450, 'key' => 'solid', 'name' => ['en' => 'Solid', 'bn' => 'সন্তোষজনক']],
            ['min' => 250, 'key' => 'developing', 'name' => ['en' => 'Developing', 'bn' => 'উন্নতির পথে']],
            ['min' => 0, 'key' => 'unsatisfactory', 'name' => ['en' => 'Unsatisfactory', 'bn' => 'অসন্তোষজনক']],
        ], $all, 20, ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'object', 'required' => ['min', 'key', 'name'],
            'properties' => ['min' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000], 'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,29}$'], 'name' => $names]]]),
        // How much goals count in the overall score, in percent (competencies count the rest).
        $rule('goal_share_percent', 'integer', 70, $all, 30, ['minimum' => 0, 'maximum' => 100]),
        // Goals' weights must add up to 100.
        $rule('goals_total_100', 'boolean', true, $all, 40),
        // Employees review themselves before their manager does.
        $rule('self_review', 'boolean', true, $all, 50),
        // Reminders this many days before a step's last day (and on the day before).
        $rule('reminder_days', 'integer', 3, $all, 60, ['minimum' => 1, 'maximum' => 30]),
        // The competencies "Add the usual ones" adds (per country, sector or partner).
        $rule('default_competencies', 'json', [
            ['code' => 'QUALITY', 'name' => ['en' => 'Quality of work', 'bn' => 'কাজের মান']],
            ['code' => 'TEAMWORK', 'name' => ['en' => 'Teamwork', 'bn' => 'দলগত কাজ']],
            ['code' => 'COMMUNICATION', 'name' => ['en' => 'Communication', 'bn' => 'যোগাযোগ']],
            ['code' => 'PUNCTUALITY', 'name' => ['en' => 'Punctuality and attendance', 'bn' => 'সময়ানুবর্তিতা ও উপস্থিতি']],
            ['code' => 'INITIATIVE', 'name' => ['en' => 'Initiative', 'bn' => 'উদ্যোগ']],
        ], $all, 70, ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'required' => ['code', 'name'],
            'properties' => ['code' => ['type' => 'string', 'maxLength' => 20], 'name' => $names]]]),
    ],
    'menu' => [
        [
            'key' => 'appraisals',
            'label' => 'appraisals::module.menu',
            'route' => '/appraisals/me',
            'icon' => 'star',
            'order' => 25,
            'section' => 'people',
            'children' => [
                ['key' => 'me', 'label' => 'appraisals::module.menu_me', 'route' => '/appraisals/me', 'permission' => 'appraisals.self'],
                ['key' => 'team', 'label' => 'appraisals::module.menu_team', 'route' => '/appraisals/team', 'permission' => 'appraisals.review'],
                ['key' => 'cycles', 'label' => 'appraisals::module.menu_cycles', 'route' => '/appraisals/cycles', 'permission' => 'appraisals.view'],
            ],
        ],
    ],
    'quick_actions' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'in_progress', 'label' => 'appraisals::dashboard.in_progress', 'type' => 'stat', 'provider' => ReviewsInProgress::class, 'permission' => 'appraisals.view'],
    ]],
    'attention' => [MyAppraisal::class, TeamAppraisals::class],
    'notifications' => [
        // To each employee of a cycle just opened.
        'appraisals.opened' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'cycle', 'due', 'link'],
            'audience' => 'client',
            'path' => '/appraisals/me',
        ],
        // To whoever acts next on a review (no ratings or comments in the mail).
        'appraisals.your_turn' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'cycle', 'employee', 'step', 'due', 'link'],
            'audience' => 'client',
            'path' => '/appraisals/me',
        ],
        'appraisals.reminder' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'cycle', 'employee', 'step', 'due', 'link'],
            'audience' => 'client',
            'path' => '/appraisals/me',
        ],
    ],
    'settings' => ['pages' => [
        ['key' => 'competencies', 'label' => 'appraisals::module.menu_competencies', 'route' => '/appraisals/competencies', 'permission' => 'appraisals.manage'],
    ]],
];
