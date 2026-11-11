<?php

// Errors and checks.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its units: a group keeps no records of its own.',
        'version_conflict' => 'Someone changed this a moment ago. It is fresh now; make your change again.',
        'no_currency' => 'Set the company\'s currency first (company settings).',
        'bad_link' => 'Choose one from this company (and this unit or one below it).',
        'in_use' => 'Other records still use this one. Change or remove them first.',
        'ward_not_found' => 'That ward or cabin does not exist here. Reload the page.',
        'bed_not_found' => 'That bed is not here or not in use. Pick another.',
        'patient_not_found' => 'That patient is not here. Search again.',
        'admission_not_found' => 'That admission is not here. Reload the page.',
        'already_admitted' => 'This patient is already admitted. Open their admission instead.',
        'bed_taken' => 'Someone else took this bed a moment ago, or it is being cleaned. Pick another bed.',
        'advance_needed' => 'An advance is needed before admission. Take it in Patient billing, then admit.',
        'not_admitted' => 'This patient is no longer admitted.',
        'same_bed' => 'The patient is already in this bed.',
        'bill_final' => 'The bill is already final, so the admission cannot be cancelled. Discharge the patient instead.',
        'bed_occupied' => 'A patient is in this bed. Move them first.',
        'cannot_set_bed' => 'This bed cannot be set that way now. Reload the board.',
        'care_only' => 'Only doctors, nurses and those who discharge read care notes. Ask an admin for access.',
        'lab_off' => 'The lab (Diagnostics) is off here, so tests cannot be sent. Write them in the round note instead.',
        'already_signed' => 'The discharge is already signed. Use "Amend" to put it right.',
        'summary_needed' => 'Write the discharge summary (at least the diagnosis) and save it first.',
        'dues_left' => 'Money is still due on the bill. Collect it (or adjust the advance) in Patient billing, then discharge.',
        'not_discharged' => 'The patient is not discharged yet, so there is nothing to amend.',
        'discharge_not_found' => 'There is no signed discharge for this admission yet.',
    ],
    'validation' => [
        'taken' => 'Another bed of this ward already has this code.',
        'service_code' => 'Patient billing has no service with this code ("Services and prices"). Add it there first.',
        'not_staff' => 'Pick a staff member of this company as the doctor.',
        'empty_note' => 'Write a note, a vital sign or a medicine given.',
        'test' => 'The lab has no test in use with this code. Pick one from the list.',
    ],
    'timeline' => [
        'discharged' => 'Discharged (:number, :ward)',
    ],
];
