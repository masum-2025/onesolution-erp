<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

return [
    'key' => 'education_fees',
    'name' => 'education_fees::module.name',
    'description' => 'education_fees::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Students, sessions, classes and sections come from Education (its AcademicDirectory).
    'requires' => ['education'],
    'sectors' => ['school', 'college', 'university', 'madrasa', 'coaching'],
    'plans' => ['*'],
    'permissions' => [
        'education_fees.view', 'education_fees.configure', 'education_fees.bill', 'education_fees.concede',
        'education_fees.approve', 'education_fees.collect', 'education_fees.void',
    ],
    'rules' => [
        [
            'key' => 'education_fees.due_day',
            'type' => 'integer',
            // Day of the month a monthly bill is due when the run names no date.
            'schema' => ['minimum' => 1, 'maximum' => 28],
            'default' => 10,
            'label' => 'education_fees::rules.due_day.label',
            'description' => 'education_fees::rules.due_day.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'billing',
            'sort_order' => 10,
        ],
        [
            'key' => 'education_fees.bill_number_format',
            'type' => 'string',
            // Placeholders: {YYYY} {YY} {SEQ:n}; counted per year.
            'schema' => ['minLength' => 3, 'maxLength' => 40, 'pattern' => '^[A-Za-z0-9{}:_/-]*\{SEQ(:\d+)?\}[A-Za-z0-9{}:_/-]*$'],
            'default' => 'FEE-{YYYY}-{SEQ:6}',
            'label' => 'education_fees::rules.bill_number_format.label',
            'description' => 'education_fees::rules.bill_number_format.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'billing',
            'sort_order' => 20,
        ],
        [
            'key' => 'education_fees.auto_monthly_billing',
            'type' => 'json',
            // Bill every campus's monthly fees on this day without the office (off: the office runs it).
            'schema' => [
                'type' => 'object', 'required' => ['enabled', 'day'], 'additionalProperties' => false,
                'properties' => ['enabled' => ['type' => 'boolean'], 'day' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 28]],
            ],
            'default' => ['enabled' => false, 'day' => 1],
            'label' => 'education_fees::rules.auto_monthly_billing.label',
            'description' => 'education_fees::rules.auto_monthly_billing.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'billing',
            'sort_order' => 30,
        ],
        [
            'key' => 'education_fees.bill_on_admission',
            'type' => 'boolean',
            // A newly admitted student is billed the "on admission" heads at once.
            'default' => true,
            'label' => 'education_fees::rules.bill_on_admission.label',
            'description' => 'education_fees::rules.bill_on_admission.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'billing',
            'sort_order' => 40,
        ],
        [
            'key' => 'education_fees.fees_include_tax',
            'type' => 'boolean',
            // Only for heads with a tax code: the fee already contains the tax (else it is added on top).
            'default' => true,
            'label' => 'education_fees::rules.fees_include_tax.label',
            'description' => 'education_fees::rules.fees_include_tax.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'billing',
            'sort_order' => 50,
        ],
        [
            'key' => 'education_fees.sibling_discount_percent',
            'type' => 'integer',
            // Off heads that allow it, for the second and later brothers and sisters studying here (0: none).
            'schema' => ['minimum' => 0, 'maximum' => 100],
            'default' => 0,
            'label' => 'education_fees::rules.sibling_discount_percent.label',
            'description' => 'education_fees::rules.sibling_discount_percent.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'discounts',
            'sort_order' => 60,
        ],
        [
            'key' => 'education_fees.concession_approval_above_percent',
            'type' => 'integer',
            // A discount above this percent (or any fixed amount) waits for another person; empty: never.
            'schema' => ['minimum' => 0, 'maximum' => 100],
            'nullable' => true,
            'default' => 0,
            'label' => 'education_fees::rules.concession_approval_above_percent.label',
            'description' => 'education_fees::rules.concession_approval_above_percent.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'sensitive' => true,
            'category' => 'discounts',
            'sort_order' => 70,
        ],
        [
            'key' => 'education_fees.late_fine',
            'type' => 'json',
            // Off by default. once: one fine; per_day / per_month: each day or month late, up to the most (0: no most).
            'schema' => [
                'type' => 'object', 'required' => ['enabled', 'mode', 'amount_minor', 'grace_days', 'max_minor'], 'additionalProperties' => false,
                'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'mode' => ['enum' => ['once', 'per_day', 'per_month']],
                    'amount_minor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
                    'grace_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365],
                    'max_minor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000000],
                ],
            ],
            'default' => ['enabled' => false, 'mode' => 'once', 'amount_minor' => 0, 'grace_days' => 7, 'max_minor' => 0],
            'label' => 'education_fees::rules.late_fine.label',
            'description' => 'education_fees::rules.late_fine.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'fines',
            'sort_order' => 80,
        ],
    ],
    // Screens come with FEE-3.
    'menu' => [],
    // Income each fee head may go to (a head names one); receivable, advance and fines come with collections.
    'ledger_accounts' => [
        'education_fees.tuition_income' => ['label' => 'education_fees::fees.posting_keys.tuition_income', 'type' => 'income'],
        'education_fees.admission_income' => ['label' => 'education_fees::fees.posting_keys.admission_income', 'type' => 'income'],
        'education_fees.exam_income' => ['label' => 'education_fees::fees.posting_keys.exam_income', 'type' => 'income'],
        'education_fees.transport_income' => ['label' => 'education_fees::fees.posting_keys.transport_income', 'type' => 'income'],
        'education_fees.other_income' => ['label' => 'education_fees::fees.posting_keys.other_income', 'type' => 'income'],
    ],
    // Collections (FEE-2) and the portal listen to these; payloads carry ids only.
    'events' => ['education_fees.bill_issued', 'education_fees.bill_cancelled'],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
