<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

return [
    'key' => 'education_exams',
    'name' => 'education_exams::module.name',
    'description' => 'education_exams::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Students, sections, subjects, curricula and teachers come from Education (its AcademicDirectory).
    'requires' => ['education'],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => [
        'education_exams.view', 'education_exams.manage', 'education_exams.enter_marks', 'education_exams.moderate',
        'education_exams.publish', 'education_exams.correct',
    ],
    'rules' => [
        [
            'key' => 'education_exams.default_grading_scheme',
            'type' => 'string',
            // The scheme a new exam starts with (a code of the institution's schemes); each exam may pick another.
            'schema' => ['minLength' => 2, 'maxLength' => 30, 'pattern' => '^[a-z0-9_]+$'],
            'default' => 'percentage',
            'label' => 'education_exams::rules.default_grading_scheme.label',
            'description' => 'education_exams::rules.default_grading_scheme.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'grading',
            'sort_order' => 10,
        ],
        [
            'key' => 'education_exams.moderation_required',
            'type' => 'boolean',
            // Handed-in marks wait for someone else (a head of department) to approve them.
            'default' => true,
            'label' => 'education_exams::rules.moderation_required.label',
            'description' => 'education_exams::rules.moderation_required.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'marks',
            'sort_order' => 20,
        ],
    ],
    // Screens come with EXM-3.
    'menu' => [],
    // Results (EXM-2) and course registration outcomes listen to these; payloads carry ids only.
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
