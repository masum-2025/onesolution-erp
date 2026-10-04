<?php

return [
    'errors' => [
        'not_company_unit' => 'Choose a company, branch or department; a group keeps no attendance.',
        'employee_not_found' => 'Employee not found here. Check the unit or search the list.',
        'shift_not_found' => 'Shift not found. Reload the list of shifts.',
        'holiday_not_found' => 'Holiday not found. Reload the list.',
        'punch_not_found' => 'Punch not found. Reload the page.',
        'correction_not_found' => 'Correction not found. Reload the list.',
        'not_linked' => 'Your login is not linked to an employee of this company. Ask HR to link it.',
        'self_punch_off' => 'Checking in yourself is turned off here. Ask your supervisor to record it.',
        'location_needed' => 'Checking in here needs your location, which this screen cannot check yet. Ask your supervisor to record it.',
        'not_employed' => 'The employee is not employed on that day.',
        'punch_voided' => 'This punch is already voided.',
        'correction_decided' => 'This correction was already decided. Reload the list.',
        'own_correction' => 'Another person has to decide a correction you asked for, or one about your own day.',
        'unknown_step' => 'Unknown step.',
        'range_too_long' => 'Choose at most :days days at a time.',
        'version_conflict' => 'Someone changed this meanwhile. Reload and try again.',
    ],

    'validation' => [
        'code_taken' => 'Another shift of this company already has this code.',
        'break_too_long' => 'The break must be shorter than the shift.',
        'shift_inactive' => 'Choose an active shift.',
        'correction_window' => 'Choose a day from today back to :days days ago.',
        'out_before_in' => 'The out time must be after the in time.',
        'time_off_day' => 'The time must be on the day chosen (or the next morning for a night shift).',
        'future_punch' => 'A punch cannot be in the future.',
    ],
];
