<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'accounting',
    'name' => 'accounting::module.name',
    'description' => 'accounting::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['accounting.view', 'accounting.post', 'accounting.approve'],
    'rules' => [
        [
            'key' => 'accounting.fiscal_year_start',
            'type' => 'date',
            // MM-DD
            'schema' => ['pattern' => '^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$'],
            'default' => '01-01',
            'label' => 'accounting::rules.fiscal_year_start.label',
            'description' => 'accounting::rules.fiscal_year_start.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'sensitive' => true,
            'category' => 'period',
            'sort_order' => 10,
        ],
        [
            'key' => 'accounting.journal_approval_above',
            'type' => 'money',
            'schema' => ['properties' => ['amount' => ['minimum' => 0]]],
            // null = journals never need a second approval.
            'nullable' => true,
            'default' => null,
            'label' => 'accounting::rules.journal_approval_above.label',
            'description' => 'accounting::rules.journal_approval_above.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'approval',
            'sort_order' => 20,
        ],
        [
            'key' => 'accounting.allow_backdated_entries_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 366],
            'default' => 7,
            'label' => 'accounting::rules.allow_backdated_entries_days.label',
            'description' => 'accounting::rules.allow_backdated_entries_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'period',
            'sort_order' => 30,
        ],
    ],
    'menu' => [
        [
            'key' => 'accounting',
            'label' => 'accounting::module.menu',
            'route' => '/accounting',
            'icon' => 'book',
            'order' => 40,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
