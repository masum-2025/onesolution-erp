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
        'location_needed' => 'Checking in here needs your location. Allow location for this app and try again.',
        'not_employed' => 'The employee is not employed on that day.',
        'punch_voided' => 'This punch is already voided.',
        'correction_decided' => 'This correction was already decided. Reload the list.',
        'own_correction' => 'Another person has to decide a correction you asked for, or one about your own day.',
        'unknown_step' => 'Unknown step.',
        'range_too_long' => 'Choose at most :days days at a time.',
        'version_conflict' => 'Someone changed this meanwhile. Reload and try again.',
        'location_not_found' => 'Workplace not found. Reload the list.',
        'no_workplaces' => 'No workplace is set for your unit yet, so checking in here cannot be checked. Ask HR to add one.',
        'location_vague' => 'Your phone knows your place only within :accuracy m (at most :max m counts). Go outdoors or turn on precise location and try again.',
        'outside_workplace' => 'You are about :metres m outside :name. Check in when you are there.',
        'offline_too_old' => 'This check-in was kept offline for more than :hours hours and no longer counts. Ask for a correction.',
        'offline_future' => 'The phone\'s clock was ahead, so this check-in cannot count. Set the phone\'s time and ask for a correction.',
    ],

    // Attendance machine files (ATT-3).
    'device' => [
        'file_size' => 'The file is larger than :max KB. Export a shorter period from the machine.',
        'file_type' => 'Choose a CSV file (UTF-8) exported from the machine.',
        'too_many_rows' => 'The file has more than :max lines. Export a shorter period from the machine.',
        'empty' => 'The file has a heading row but no lines.',
        'column_missing' => 'The file has no column ":column". Choose the columns again.',
        'time_columns' => 'Choose the time column, or the date and time columns.',
        'row' => 'Line :row: ":value" is not a time written as :format.',
    ],
    'validation' => [
        'code_taken' => 'Another shift of this company already has this code.',
        'break_too_long' => 'The break must be shorter than the shift.',
        'shift_inactive' => 'Choose an active shift.',
        'correction_window' => 'Choose a day from today back to :days days ago.',
        'out_before_in' => 'The out time must be after the in time.',
        'time_off_day' => 'The time must be on the day chosen (or the next morning for a night shift).',
        'future_punch' => 'A punch cannot be in the future.',
        'point' => 'Give a real latitude and longitude.',
    ],
];
