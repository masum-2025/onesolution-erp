<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'hrm',
    'name' => 'hrm::module.name',
    'description' => 'hrm::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['hrm.view', 'hrm.manage'],
    'rules' => [
        [
            'key' => 'hrm.probation_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 365],
            'default' => 90,
            'label' => 'hrm::rules.probation_days.label',
            'description' => 'hrm::rules.probation_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'category' => 'employment',
            'sort_order' => 10,
        ],
        [
            'key' => 'hrm.notice_period_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 180],
            'default' => 30,
            'label' => 'hrm::rules.notice_period_days.label',
            'description' => 'hrm::rules.notice_period_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'employment',
            'sort_order' => 20,
        ],
        [
            'key' => 'hrm.employee_code_format',
            'type' => 'string',
            // Placeholders: {BRANCH} {YYYY} {SEQ:n}
            'schema' => ['minLength' => 3, 'maxLength' => 50, 'pattern' => '^[A-Za-z0-9{}:_/-]+$'],
            'default' => 'EMP-{YYYY}-{SEQ:4}',
            'label' => 'hrm::rules.employee_code_format.label',
            'description' => 'hrm::rules.employee_code_format.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 30,
        ],
    ],
    'menu' => [
        [
            'key' => 'hrm',
            'label' => 'hrm::module.menu',
            'route' => '/hrm',
            'icon' => 'users',
            'order' => 10,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
