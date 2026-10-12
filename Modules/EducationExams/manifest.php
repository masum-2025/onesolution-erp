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
        [
            'key' => 'education_exams.fail_any_subject',
            'type' => 'boolean',
            // Failing any counted subject fails the whole result (grade point 0).
            'default' => true,
            'label' => 'education_exams::rules.fail_any_subject.label',
            'description' => 'education_exams::rules.fail_any_subject.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'results',
            'sort_order' => 30,
        ],
        [
            'key' => 'education_exams.optional_bonus_above',
            'type' => 'integer',
            // An optional (4th) subject adds the grade points it earns above this (hundredths: 200 = 2.00); empty: no bonus.
            'schema' => ['minimum' => 0, 'maximum' => 1000],
            'nullable' => true,
            'default' => 200,
            'label' => 'education_exams::rules.optional_bonus_above.label',
            'description' => 'education_exams::rules.optional_bonus_above.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'results',
            'sort_order' => 40,
        ],
        [
            'key' => 'education_exams.gpa_by_credits',
            'type' => 'boolean',
            // Weigh subjects by their credits (universities) instead of counting each once.
            'default' => false,
            'label' => 'education_exams::rules.gpa_by_credits.label',
            'description' => 'education_exams::rules.gpa_by_credits.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'results',
            'sort_order' => 50,
        ],
        [
            'key' => 'education_exams.show_rank',
            'type' => 'boolean',
            // Merit positions in the class and the section on results and marksheets.
            'default' => true,
            'label' => 'education_exams::rules.show_rank.label',
            'description' => 'education_exams::rules.show_rank.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'results',
            'sort_order' => 60,
        ],
        [
            'key' => 'education_exams.correction_needs_approval',
            'type' => 'boolean',
            // Changing a published mark waits for someone other than who asked.
            'default' => true,
            'label' => 'education_exams::rules.correction_needs_approval.label',
            'description' => 'education_exams::rules.correction_needs_approval.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'sensitive' => true,
            'category' => 'results',
            'sort_order' => 70,
        ],
    ],
    // Screens come with EXM-3.
    'menu' => [],
    // Results (EXM-2) and course registration outcomes listen to these; payloads carry ids only.
    'events' => ['education_exams.result_published', 'education_exams.marks_corrected'],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
