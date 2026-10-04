<?php

return [
    'errors' => [
        'not_company_unit' => 'Choose a company, branch or department; a group keeps no payroll.',
        'no_currency' => 'The company has no currency yet. Set its country or currency first.',
        'employee_not_found' => 'Employee not found here. Check the unit, or ask HR to link your login.',
        'component_not_found' => 'Component not found. Reload the list.',
        'structure_not_found' => 'Structure not found. Reload the list.',
        'run_not_found' => 'Payroll not found. Reload the list.',
        'adjustment_not_found' => 'Adjustment not found. Reload the payroll.',
        'run_exists' => 'The payroll of :period is already open. Open it from the list.',
        'not_draft' => 'This payroll is no longer a draft. A sent one is changed only after it is sent back.',
        'not_pending' => 'This payroll is not waiting for approval.',
        'not_approved' => 'Only an approved payroll can be marked paid.',
        'not_calculated' => 'Calculate the payroll (again) before sending it.',
        'slip_problems' => ':count payslips have a problem (no salary, or pay below zero). Fix them and calculate again.',
        'own_run' => 'Another person has to approve a payroll you opened or sent.',
        'already_approved' => 'You approved this payroll already; the next level needs someone else.',
        'unknown_step' => 'Unknown step.',
        'version_conflict' => 'Someone changed this meanwhile. Reload and try again.',
    ],

    'validation' => [
        'code_taken' => 'Another item of this company already has this code.',
        'component' => 'Choose an active component of this company, each once.',
        'item_value' => 'Give the amount for a fixed item, or the share of the basic for the others.',
        'structure' => 'Choose an active pay structure.',
        'before_joining' => 'The salary cannot start before the employee joined.',
        'account_number' => 'Give the account number, or choose cash.',
        'not_in_period' => 'The employee is not employed in this month.',
    ],

    // Narration of the journals payroll posts.
    'narration' => [
        'run' => 'Salaries :period',
        'payment' => 'Salaries :period paid',
    ],

    'posting_keys' => [
        'salary_expense' => 'Salaries and wages (expense)',
        'salaries_payable' => 'Salaries payable',
        'tax_payable' => 'Tax withheld from salaries (payable)',
        'deductions_payable' => 'Other salary deductions (payable)',
        'payment_account' => 'Account salaries are paid from',
    ],
];
