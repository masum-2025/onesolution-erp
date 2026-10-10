<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\Education\Dashboard\EducationWidgets;
use Modules\Education\Portal\StudentSubjects;

return [
    'key' => 'education',
    'name' => 'education::module.name',
    'description' => 'education::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Works alone; picks class teachers from HRM when it is on.
    'requires' => [],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => [
        'education.view', 'education.manage', 'education.admit', 'education.edit_students',
        'education.view_sensitive', 'education.promote', 'education.approve_promotion',
    ],
    'rules' => [
        [
            'key' => 'education.student_code_format',
            'type' => 'string',
            // {YYYY} {YY} year admitted, {PROGRAM} program code, {SEQ:n} number of n digits (one per year).
            'schema' => ['pattern' => '^(?=.*\\{SEQ(:[3-8])?\\})[A-Za-z0-9{}:\\-\\/]{4,40}$'],
            'default' => '{YYYY}{SEQ:4}',
            'label' => 'education::rules.student_code_format.label',
            'description' => 'education::rules.student_code_format.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 10,
        ],
        [
            'key' => 'education.number_prefixes',
            'type' => 'json',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'admission' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$'],
                    'promotion' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$'],
                ],
                'additionalProperties' => false,
            ],
            'default' => ['admission' => 'ADM', 'promotion' => 'PRM'],
            'label' => 'education::rules.number_prefixes.label',
            'description' => 'education::rules.number_prefixes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 20,
        ],
        [
            'key' => 'education.section_capacity_default',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 2000],
            'default' => 40,
            'label' => 'education::rules.section_capacity_default.label',
            'description' => 'education::rules.section_capacity_default.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'sections',
            'sort_order' => 30,
        ],
        [
            'key' => 'education.roll_number_mode',
            'type' => 'enum',
            'schema' => ['enum' => ['manual', 'name', 'admission_order']],
            'default' => 'admission_order',
            'label' => 'education::rules.roll_number_mode.label',
            'description' => 'education::rules.roll_number_mode.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'sections',
            'sort_order' => 40,
        ],
        [
            'key' => 'education.promotion_approval',
            'type' => 'boolean',
            'default' => false,
            'label' => 'education::rules.promotion_approval.label',
            'description' => 'education::rules.promotion_approval.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'sensitive' => true,
            'category' => 'promotion',
            'sort_order' => 50,
        ],
        [
            'key' => 'education.promotion_undo_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 365],
            'default' => 30,
            'label' => 'education::rules.promotion_undo_days.label',
            'description' => 'education::rules.promotion_undo_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'promotion',
            'sort_order' => 60,
        ],
        [
            'key' => 'education.max_repeats',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 10],
            'default' => 2,
            'label' => 'education::rules.max_repeats.label',
            'description' => 'education::rules.max_repeats.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'promotion',
            'sort_order' => 70,
        ],
        [
            'key' => 'education.teacher_scope',
            'type' => 'enum',
            'schema' => ['enum' => ['own_sections', 'all']],
            'default' => 'own_sections',
            'label' => 'education::rules.teacher_scope.label',
            'description' => 'education::rules.teacher_scope.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'access',
            'sort_order' => 80,
        ],
        [
            'key' => 'education.crm_admission_pipelines',
            'type' => 'json',
            // CRM pipelines (by key) whose won deals become applications here.
            'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'], 'uniqueItems' => true, 'maxItems' => 10],
            'default' => ['admissions'],
            'label' => 'education::rules.crm_admission_pipelines.label',
            'description' => 'education::rules.crm_admission_pipelines.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'admissions',
            'sort_order' => 90,
        ],
    ],
    'menu' => [
        [
            'key' => 'education',
            'label' => 'education::module.menu',
            'route' => '/education',
            'icon' => 'graduation-cap',
            'order' => 40,
            'section' => 'business',
            'children' => [
                ['key' => 'overview', 'label' => 'education::module.menu_overview', 'route' => '/education', 'permission' => 'education.view'],
                ['key' => 'students', 'label' => 'education::module.menu_students', 'route' => '/education/students', 'permission' => 'education.view'],
                ['key' => 'sections', 'label' => 'education::module.menu_sections', 'route' => '/education/sections', 'permission' => 'education.view'],
                ['key' => 'admissions', 'label' => 'education::module.menu_admissions', 'route' => '/education/admissions', 'permission' => 'education.admit'],
                // Approvers (who may not make lists) reach waiting lists from the overview.
                ['key' => 'promotions', 'label' => 'education::module.menu_promotions', 'route' => '/education/promotions', 'permission' => 'education.promote'],
            ],
        ],
    ],
    // The header "New" menu.
    'quick_actions' => [
        ['key' => 'admission', 'label' => 'education::module.new_admission', 'route' => '/education/admissions?new=1', 'permission' => 'education.admit', 'icon' => 'file-plus'],
        ['key' => 'student', 'label' => 'education::module.new_student', 'route' => '/education/students?new=1', 'permission' => 'education.admit', 'icon' => 'user-plus'],
    ],
    // Other modules (fees, attendance, exams) listen to these; payloads carry ids only.
    'events' => ['education.student_admitted', 'education.student_left', 'education.enrollment_changed'],
    'portal_subjects' => [StudentSubjects::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'students', 'label' => 'education::dashboard.students', 'type' => 'stat', 'provider' => EducationWidgets::class, 'permission' => 'education.view', 'overview' => true],
    ]],
    'attention' => [EducationWidgets::class],
    'settings' => ['pages' => [
        ['key' => 'structure', 'label' => 'education::module.menu_structure', 'route' => '/education/structure', 'permission' => 'education.manage'],
        ['key' => 'fields', 'label' => 'education::module.menu_fields', 'route' => '/education/fields', 'permission' => 'education.manage'],
    ]],
];
