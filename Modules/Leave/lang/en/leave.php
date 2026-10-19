<?php

return [
    'errors' => [
        'not_company_unit' => 'Leave is kept for a company and its branches. Open a company or a branch.',
        'employee_not_found' => 'This employee was not found here.',
        'type_not_found' => 'This kind of leave was not found.',
        'request_not_found' => 'This leave request was not found.',
        'not_linked' => 'Your login is not linked to an employee record. Ask HR to link it.',
        'type_closed' => 'This kind of leave is not open to you. Choose another one.',
        'not_employed' => 'The employee is not employed on all of these days. Check the dates.',
        'not_enough' => 'Only :days day(s) of this leave are left. Ask for fewer days or choose another kind of leave.',
        'overlaps' => 'These days overlap leave already asked for or approved. Change the dates or cancel the other request.',
        'crosses_year' => 'A request must stay inside one leave year. Ask for the days of each year separately.',
        'no_working_days' => 'There are no working days in these dates (weekends and holidays do not count). Check the dates.',
        'version_conflict' => 'Someone changed this in the meantime. Reload and try again.',
        'decided' => 'This request has already been decided.',
        'own_request' => 'You cannot decide your own leave, or one you asked for. Someone else must.',
        'not_your_step' => 'This step is for someone else (the employee\'s manager first) or you already approved a step.',
        'cannot_cancel' => 'This leave cannot be cancelled now. Ask HR.',
        'not_encashable' => 'This kind of leave cannot be paid out. Turn "can be paid out" on for it first.',
        'encashment_not_found' => 'This pay-out was not found.',
        'own_encashment' => 'You cannot decide a pay-out you asked for, or of your own leave. Someone else must.',
        'code_taken' => 'Another kind of leave already has this code. Choose another code.',
    ],
    'validation' => [
        'no_half_days' => 'This kind of leave is taken in whole days.',
        'one_half_only' => 'For one day, choose either the morning or the afternoon, not both.',
        'too_far_back' => 'Leave can start at most :days day(s) back.',
        'days_needed' => 'Give at least half a day.',
        'own_only' => 'Ask for your own leave here. HR asks for others from the team list.',
    ],
    'status' => [
        'pending' => 'Waiting',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ],
    'encash' => [
        'slip_line' => 'Leave paid out (:type)',
    ],
    'types' => [
        'defaults_added' => '{0} The usual kinds of leave are already there.|{1} :count kind of leave added.|[2,*] :count kinds of leave added.',
    ],
];
