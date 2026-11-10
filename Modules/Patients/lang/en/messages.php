<?php

// Errors and other server texts.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its branches: a group keeps no patients of its own.',
        'version_conflict' => 'Someone changed this a moment ago. It has been loaded again; make your change once more.',
        'no_currency' => 'Set the company currency first (company settings).',
        'patient_not_found' => 'This patient is not here. Open the page again or search once more.',
        'merge_not_found' => 'This merge request is not here. Open the page again.',
        'possible_duplicate' => 'This patient may already be registered. Check the list; if it is someone else, tap "Register anyway".',
        'id_number_required' => 'Enter the ID number (NID, birth registration or passport): this company needs it for every new patient.',
        'bad_mobile' => 'This mobile number does not look right. Type it like 01712345678.',
        'age_or_birth_date' => 'Enter the date of birth, or the age in years if the date is not known.',
        'patient_merged' => 'This record was merged into another patient. The other record has been opened.',
        'merge_self' => 'Choose a different patient to merge into.',
        'merge_waiting' => 'A merge request for this patient is already waiting. Decide that one first.',
        'own_request' => 'You asked for this merge, so someone else must decide it.',
        'not_pending' => 'This request has already been decided.',
    ],
    'timeline' => [
        'registered' => 'Registered as a patient',
    ],
    'sexes' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
    'portal' => [
        'subject' => 'Patient',
        'name' => 'Name',
        'mrn' => 'Patient number',
        'age' => 'Age',
        'sex' => 'Sex',
        'blood_group' => 'Blood group',
    ],
];
