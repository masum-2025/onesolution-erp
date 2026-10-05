<?php

return [
    'overtime_multiplier' => [
        'label' => 'Overtime rate multiplier',
        'description' => 'Overtime pay as a multiple of the ordinary hourly rate.',
    ],
    'pay_cycle' => [
        'label' => 'Pay cycle',
        'description' => 'How often salaries are paid.',
        'options' => [
            'monthly' => 'Monthly',
            'biweekly' => 'Every two weeks',
        ],
    ],
    'tax_slabs' => [
        'label' => 'Income tax slabs',
        'description' => 'Tax rate for each band of taxable income.',
    ],
    'salary_approval_levels' => [
        'label' => 'Salary approval levels',
        'description' => 'How many people must approve a payroll run.',
    ],

    'deduct_absence' => [
        'label' => 'Deduct absent days',
        'description' => 'Pay for days absent (and half of half days) is deducted from prorated earnings.',
    ],
    'late_deduction' => [
        'label' => 'Deduct late minutes',
        'description' => 'Minutes late are deducted at the basic\'s minute rate.',
    ],
    'overtime_base' => [
        'label' => 'Over time paid on',
        'description' => 'Whether the hourly rate for over time comes from the basic or the full gross.',
        'options' => ['basic' => 'Basic', 'gross' => 'Gross'],
    ],
    'monthly_hours' => [
        'label' => 'Hours a month\'s pay stands for',
        'description' => 'The month\'s pay over these hours is the hourly rate (26 days x 8 hours = 208).',
    ],
    'loan_max_installments' => [
        'label' => 'Most instalments for a loan',
        'description' => 'A loan or advance is recovered in at most this many monthly instalments.',
    ],
    'loan_max_basic_multiple' => [
        'label' => 'Largest loan, in months of basic',
        'description' => 'A loan may be at most this many months of the employee\'s basic salary (0 = no limit).',
    ],
    'bonus_min_service_months' => [
        'label' => 'Service needed for a festival bonus (months)',
        'description' => 'Employees with less service get no festival bonus unless an amount is set by hand.',
    ],
    'bonus_percent_of_basic' => [
        'label' => 'Festival bonus (% of basic)',
        'description' => 'The usual festival bonus as a percentage of the basic salary (100 = one month).',
    ],
    'bonus_taxable' => [
        'label' => 'Tax festival bonuses',
        'description' => 'Withhold income tax on festival bonuses, on top of the regular salary.',
    ],
    'pf_enabled' => [
        'label' => 'Provident fund',
        'description' => 'Take the employee\'s share from each month\'s pay and add the company\'s share to their fund.',
    ],
    'pf_employee_percent' => [
        'label' => 'Provident fund, employee share (% of basic)',
        'description' => 'Taken from the basic paid each month.',
    ],
    'pf_employer_percent' => [
        'label' => 'Provident fund, company share (% of basic)',
        'description' => 'Added by the company each month, an expense of the company.',
    ],
    'pf_vesting' => [
        'label' => 'Company share kept on leaving',
        'description' => 'After so many years of service, this percent of the company\'s contributions goes with the employee. Empty: all of it.',
    ],
    'gratuity_min_years' => [
        'label' => 'Gratuity from (years of service)',
        'description' => 'No gratuity for less service.',
    ],
    'gratuity_days_per_year' => [
        'label' => 'Gratuity (days of basic per year)',
        'description' => 'Days of the basic for each whole year of service (a month counts as 30 days). 0 = no gratuity.',
    ],
    'categories' => [
        'fund' => 'Provident fund',
        'leaving' => 'Leaving',
        'loans' => 'Loans and advances',
        'bonus' => 'Festival bonus',
        'attendance' => 'Attendance',
        'approval' => 'Approvals',
        'cycle' => 'Pay cycle',
        'overtime' => 'Overtime',
        'tax' => 'Tax',
    ],
];
