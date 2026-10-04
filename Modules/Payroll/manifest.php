<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

use Modules\Payroll\Dashboard\LastPayroll;
use Modules\Payroll\Dashboard\PayrollWidgets;

return [
    'key' => 'payroll',
    'name' => 'payroll::module.name',
    'description' => 'payroll::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['hrm', 'attendance'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['payroll.view', 'payroll.run', 'payroll.approve'],
    // Whoever runs payroll must not approve it (default of access.separation_of_duties).
    'separation_of_duties' => [['payroll.run', 'payroll.approve']],
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
        [
            'key' => 'payroll.deduct_absence',
            'type' => 'boolean',
            'default' => true,
            'label' => 'payroll::rules.deduct_absence.label',
            'description' => 'payroll::rules.deduct_absence.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'attendance',
            'sort_order' => 50,
        ],
        [
            'key' => 'payroll.late_deduction',
            'type' => 'boolean',
            'default' => false,
            'label' => 'payroll::rules.late_deduction.label',
            'description' => 'payroll::rules.late_deduction.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'attendance',
            'sort_order' => 55,
        ],
        [
            'key' => 'payroll.overtime_base',
            'type' => 'enum',
            // Bangladesh: twice the basic (Labour Act); others often the full gross.
            'schema' => ['enum' => ['basic', 'gross']],
            'default' => 'basic',
            'label' => 'payroll::rules.overtime_base.label',
            'description' => 'payroll::rules.overtime_base.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'overtime',
            'sort_order' => 12,
        ],
        [
            'key' => 'payroll.monthly_hours',
            'type' => 'integer',
            // The hours a month's pay stands for (26 days x 8 hours = 208).
            'schema' => ['minimum' => 100, 'maximum' => 300],
            'default' => 208,
            'label' => 'payroll::rules.monthly_hours.label',
            'description' => 'payroll::rules.monthly_hours.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'overtime',
            'sort_order' => 14,
        ],
    ],
    'menu' => [
        [
            'key' => 'payroll',
            'label' => 'payroll::module.menu',
            'route' => '/payroll',
            'icon' => 'banknote',
            'order' => 30,
            'section' => 'people',
            'children' => [
                ['key' => 'runs', 'label' => 'payroll::module.menu_runs', 'route' => '/payroll', 'permission' => 'payroll.view'],
                ['key' => 'employees', 'label' => 'payroll::module.menu_employees', 'route' => '/payroll/employees', 'permission' => 'payroll.view'],
                ['key' => 'my_slips', 'label' => 'payroll::module.menu_my_slips', 'route' => '/payroll/me'],
            ],
        ],
    ],
    // Other modules listen to these; payloads carry ids only.
    'events' => ['payroll.run.approved'],
    // Where approved and paid salaries go in the books (Accounting maps each to an account).
    'ledger_accounts' => [
        'payroll.salary_expense' => ['label' => 'payroll::payroll.posting_keys.salary_expense', 'type' => 'expense'],
        'payroll.salaries_payable' => ['label' => 'payroll::payroll.posting_keys.salaries_payable', 'type' => 'liability'],
        'payroll.tax_payable' => ['label' => 'payroll::payroll.posting_keys.tax_payable', 'type' => 'liability'],
        'payroll.deductions_payable' => ['label' => 'payroll::payroll.posting_keys.deductions_payable', 'type' => 'liability'],
        'payroll.payment_account' => ['label' => 'payroll::payroll.posting_keys.payment_account', 'type' => 'asset'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'last', 'label' => 'payroll::dashboard.last', 'type' => 'stat', 'provider' => LastPayroll::class, 'permission' => 'payroll.view', 'overview' => true],
        ['key' => 'waiting', 'label' => 'payroll::dashboard.waiting', 'type' => 'stat', 'provider' => PayrollWidgets::class, 'permission' => 'payroll.view'],
    ]],
    'attention' => [PayrollWidgets::class],
    'settings' => ['pages' => [
        ['key' => 'components', 'label' => 'payroll::module.menu_components', 'route' => '/payroll/components', 'permission' => 'payroll.view'],
        ['key' => 'structures', 'label' => 'payroll::module.menu_structures', 'route' => '/payroll/structures', 'permission' => 'payroll.view'],
    ]],
    // An employee opens their own payslips from their record in the client's portal.
    'portal_pages' => [
        ['subject' => 'hrm.employee', 'label' => 'payroll::module.portal_slips', 'route' => '/portal/payslips'],
    ],
];
