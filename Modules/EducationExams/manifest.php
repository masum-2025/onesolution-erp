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
            'key' => 'education_exams.class_teacher_sees_section_results',
            'type' => 'boolean',
            // A class teacher sees the whole result of their section (every subject, GPA, position); off: only the subjects they enter.
            'default' => true,
            'label' => 'education_exams::rules.class_teacher_sees_section_results.label',
            'description' => 'education_exams::rules.class_teacher_sees_section_results.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'access',
            'sort_order' => 80,
        ],
        [
            'key' => 'education_exams.subject_teacher_sees_section_results',
            'type' => 'boolean',
            // A subject teacher sees the whole result of the sections they teach; off: only their own subject's marks.
            'default' => false,
            'label' => 'education_exams::rules.subject_teacher_sees_section_results.label',
            'description' => 'education_exams::rules.subject_teacher_sees_section_results.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'access',
            'sort_order' => 90,
        ],
        [
            'key' => 'education_exams.notify_results',
            'type' => 'json',
            // Off by default (SMS costs money). On publishing, families get the student's GPA and a link, by these channels.
            'schema' => [
                'type' => 'object', 'required' => ['enabled', 'channels'], 'additionalProperties' => false,
                'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'channels' => ['type' => 'array', 'items' => ['enum' => ['sms', 'mail']], 'minItems' => 1, 'uniqueItems' => true],
                ],
            ],
            'default' => ['enabled' => false, 'channels' => ['sms', 'mail']],
            'label' => 'education_exams::rules.notify_results.label',
            'description' => 'education_exams::rules.notify_results.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'families',
            'sort_order' => 100,
        ],
        [
            'key' => 'education_exams.withhold_results_with_dues',
            'type' => 'boolean',
            // With fees on: a student who owes fees sees no result in the portal (and the message says to contact the office).
            'default' => false,
            'label' => 'education_exams::rules.withhold_results_with_dues.label',
            'description' => 'education_exams::rules.withhold_results_with_dues.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'families',
            'sort_order' => 110,
        ],
        [
            'key' => 'education_exams.marksheet_signatures',
            'type' => 'json',
            // Who signs a printed marksheet, in order.
            'schema' => ['type' => 'array', 'items' => ['enum' => ['class_teacher', 'exam_controller', 'head', 'guardian']], 'maxItems' => 4, 'uniqueItems' => true],
            'default' => ['class_teacher', 'head', 'guardian'],
            'label' => 'education_exams::rules.marksheet_signatures.label',
            'description' => 'education_exams::rules.marksheet_signatures.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'results',
            'sort_order' => 120,
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
    // Each screen shows to those holding its permission; results inside follow ResultAccess (rules above).
    'menu' => [
        [
            'key' => 'education_exams',
            'label' => 'education_exams::module.menu',
            'route' => '/exams',
            'icon' => 'clipboard',
            'order' => 43,
            'section' => 'business',
            'children' => [
                ['key' => 'exams', 'label' => 'education_exams::module.menu_exams', 'route' => '/exams', 'permission' => 'education_exams.view'],
                ['key' => 'my_sheets', 'label' => 'education_exams::module.menu_my_sheets', 'route' => '/exams/my-sheets', 'permission' => 'education_exams.enter_marks'],
                ['key' => 'combinations', 'label' => 'education_exams::module.menu_combinations', 'route' => '/exams/combinations', 'permission' => 'education_exams.view'],
                ['key' => 'corrections', 'label' => 'education_exams::module.menu_corrections', 'route' => '/exams/corrections', 'permission' => 'education_exams.publish'],
                ['key' => 'transcripts', 'label' => 'education_exams::module.menu_transcripts', 'route' => '/exams/students', 'permission' => 'education_exams.view'],
                ['key' => 'grading', 'label' => 'education_exams::module.menu_grading', 'route' => '/exams/grading', 'permission' => 'education_exams.manage'],
            ],
        ],
    ],
    // Results (EXM-2) and course registration outcomes listen to these; payloads carry ids only.
    'events' => ['education_exams.result_published', 'education_exams.marks_corrected'],
    // To families (wording in education_exams::notifications); the link opens the portal.
    'notifications' => [
        'education_exams.result_published' => ['channels' => ['mail', 'sms'], 'placeholders' => ['product', 'organization', 'student', 'exam', 'gpa', 'letter', 'link'], 'audience' => 'person', 'path' => '/portal'],
        'education_exams.result_held' => ['channels' => ['mail', 'sms'], 'placeholders' => ['product', 'organization', 'student', 'exam', 'link'], 'audience' => 'person', 'path' => '/portal'],
    ],
    // A family opens the student's results from the student's record in the portal.
    'portal_pages' => [
        ['subject' => 'education.student', 'label' => 'education_exams::module.portal_page', 'route' => '/portal/results/{record}'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
