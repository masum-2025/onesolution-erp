<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'payroll',
    'name' => 'payroll::module.name',
    'description' => 'payroll::module.description',
    'version' => '0.1.0',
    'category' => 'business',
    'requires' => ['hrm', 'attendance'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['payroll.view', 'payroll.run', 'payroll.approve'],
    'rules' => [
        [
            'key' => 'payroll.overtime_multiplier',
            'type' => 'decimal',
            // Decimal as a string; country values (e.g. BD Labour Act) are seeded data.
            'schema' => ['pattern' => '^[1-4](\.\d{1,2})?$'],
            'default' => '1.5',
            'label' => 'payroll::rules.overtime_multiplier.label',
            'description' => 'payroll::rules.overtime_multiplier.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'overtime',
            'sort_order' => 10,
        ],
        [
            'key' => 'payroll.pay_cycle',
            'type' => 'enum',
            'schema' => ['enum' => ['monthly', 'biweekly']],
            'default' => 'monthly',
            'label' => 'payroll::rules.pay_cycle.label',
            'description' => 'payroll::rules.pay_cycle.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'cycle',
            'sort_order' => 20,
        ],
        [
            'key' => 'payroll.tax_slabs',
            'type' => 'table',
            // Rows in order: taxable income up to `upto_minor` (null = rest) at `rate_percent`.
            'schema' => [
                'items' => [
                    'type' => 'object',
                    'required' => ['upto_minor', 'rate_percent'],
                    'properties' => [
                        // x-format is a display hint only: amounts in minor units of the organization's currency.
                        'upto_minor' => ['type' => ['integer', 'null'], 'minimum' => 0, 'x-format' => 'money_minor'],
                        'rate_percent' => ['type' => 'string', 'pattern' => '^\d{1,2}(\.\d{1,2})?$'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'default' => [],
            'label' => 'payroll::rules.tax_slabs.label',
            'description' => 'payroll::rules.tax_slabs.description',
            'overridable_levels' => ['platform', 'partner'],
            'country_specific' => true,
            'sensitive' => true,
            'requires_approval' => true,
            'category' => 'tax',
            'sort_order' => 30,
        ],
        [
            'key' => 'payroll.salary_approval_levels',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 5],
            'default' => 1,
            'label' => 'payroll::rules.salary_approval_levels.label',
            'description' => 'payroll::rules.salary_approval_levels.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'sensitive' => true,
            'category' => 'approval',
            'sort_order' => 40,
        ],
    ],
    'menu' => [
        [
            'key' => 'payroll',
            'label' => 'payroll::module.menu',
            'route' => '/payroll',
            'icon' => 'banknote',
            'order' => 30,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
