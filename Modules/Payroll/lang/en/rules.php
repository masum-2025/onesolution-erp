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

    'categories' => [
        'approval' => 'Approvals',
        'cycle' => 'Pay cycle',
        'overtime' => 'Overtime',
        'tax' => 'Tax',
    ],
];
