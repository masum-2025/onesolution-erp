<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\TeacherFeedback\Dashboard\FeedbackWidgets;

return [
    'key' => 'teacher_feedback',
    'name' => 'teacher_feedback::module.name',
    'description' => 'teacher_feedback::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Sections, subject teachers and students come from Education (its AcademicDirectory);
    // rating happens in the client's portal (routes also need client_portal on).
    'requires' => ['education'],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => ['teacher_feedback.manage', 'teacher_feedback.view_results', 'teacher_feedback.view_own'],
    'rules' => [
        [
            'key' => 'teacher_feedback.who_rates',
            'type' => 'enum',
            // Schools and madrasas ask guardians (sector packages); others the students themselves.
            'schema' => ['enum' => ['students', 'guardians', 'both']],
            'default' => 'students',
            'label' => 'teacher_feedback::rules.who_rates.label',
            'description' => 'teacher_feedback::rules.who_rates.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'feedback',
            'sort_order' => 10,
        ],
        [
            'key' => 'teacher_feedback.min_responses',
            'type' => 'integer',
            // Averages and comments show only from this many ratings, so nobody can be picked out.
            'schema' => ['minimum' => 3, 'maximum' => 100],
            'default' => 5,
            'label' => 'teacher_feedback::rules.min_responses.label',
            'description' => 'teacher_feedback::rules.min_responses.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'privacy',
            'sort_order' => 20,
        ],
        [
            'key' => 'teacher_feedback.allow_comments',
            'type' => 'boolean',
            'default' => true,
            'label' => 'teacher_feedback::rules.allow_comments.label',
            'description' => 'teacher_feedback::rules.allow_comments.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'feedback',
            'sort_order' => 30,
        ],
        [
            'key' => 'teacher_feedback.teachers_see_own',
            'type' => 'boolean',
            'default' => true,
            'label' => 'teacher_feedback::rules.teachers_see_own.label',
            'description' => 'teacher_feedback::rules.teachers_see_own.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'privacy',
            'sort_order' => 40,
        ],
    ],
    'menu' => [
        [
            'key' => 'teacher_feedback',
            'label' => 'teacher_feedback::module.menu',
            'route' => '/teacher-feedback',
            'icon' => 'sparkles',
            'order' => 10,
            'section' => 'education',
            // Its widget is on the education home; the menu keeps only its settings.
            'dashboard' => false,
            'children' => [
                ['key' => 'rounds', 'label' => 'teacher_feedback::module.menu_rounds', 'route' => '/teacher-feedback', 'permission' => 'teacher_feedback.view_results'],
                ['key' => 'mine', 'label' => 'teacher_feedback::module.menu_mine', 'route' => '/teacher-feedback/mine', 'permission' => 'teacher_feedback.view_own'],
            ],
        ],
    ],
    'events' => [],
    // The student's (or a parent's, for their child) feedback page from their record in the portal.
    'portal_pages' => [
        ['subject' => 'education.student', 'label' => 'teacher_feedback::module.portal_page', 'route' => '/portal/teacher-feedback/{record}'],
    ],
    'attention' => [FeedbackWidgets::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'responses', 'label' => 'teacher_feedback::dashboard.responses', 'type' => 'stat', 'provider' => FeedbackWidgets::class, 'permission' => 'teacher_feedback.view_results'],
    ]],
    'settings' => ['pages' => []],
];
