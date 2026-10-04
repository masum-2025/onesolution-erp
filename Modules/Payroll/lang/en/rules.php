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
    'categories' => [
        'attendance' => 'Attendance',
        'approval' => 'Approvals',
        'cycle' => 'Pay cycle',
        'overtime' => 'Overtime',
        'tax' => 'Tax',
    ],
];
