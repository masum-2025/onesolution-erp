<?php

// Errors and checks.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its units: a group keeps no records of its own.',
        'version_conflict' => 'Someone changed this a moment ago. It is fresh now; make your change again.',
        'no_currency' => 'Set the company\'s currency first (company settings).',
        'bad_link' => 'Choose one from this company (and this unit or one below it).',
        'in_use' => 'Other records still use this one. Change or remove them first.',
        'doctor_not_found' => 'That Doctor does not exist here. Reload the page.',
        'chamber_not_found' => 'That chamber does not exist here. Reload the page.',
        'patient_not_found' => 'That patient is not here. Search again.',
        'session_not_found' => 'That session is not held here any more. Pick another.',
        'serial_not_found' => 'That serial is not here. Reload the page.',
        'no_session' => 'The doctor does not sit in this session on that day. Pick a date the session is held.',
        'date_passed' => 'That day has gone. Pick today or a later date.',
        'too_far_ahead' => 'Serials can be given at most :days days ahead.',
        'already_booked' => 'This patient already has a serial in this session. Look them up in today\'s serials.',
        'session_full' => 'All :limit serials of this session are taken. Pick another session or day.',
        'cannot_move' => 'This serial cannot go to that step now. Reload the page to see where it is.',
        'desk_only' => 'Only the desk does this step. Ask the reception.',
        'own_patients_only' => 'You can move only your own patients\' serials.',
    ],
    'validation' => [
        'taken' => 'Another record already has this. Use a different one.',
        'service_code' => 'Patient billing has no service with this code ("Services and prices"). Add it there first, then enter the code.',
        'ends_after_start' => 'The session must end after it starts.',
        'overlap' => 'This session overlaps another of the same day. A doctor sits in one place at a time.',
        'not_staff' => 'Pick a staff member of this company.',
        'user_taken' => 'This person is already the sign-in of another doctor.',
    ],
];
