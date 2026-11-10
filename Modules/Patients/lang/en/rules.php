<?php

// Rule labels, key = part after "patients.".
return [
    'mrn_prefix' => [
        'label' => 'Patient number prefix',
        'description' => 'Letters before every new patient number, e.g. "P" gives P000123. Old numbers stay as they are.',
    ],
    'require_id_number' => [
        'label' => 'ID number required',
        'description' => 'New patients cannot be saved without an ID number (NID, birth registration or passport).',
    ],
    'duplicate_check' => [
        'label' => 'Warn about the same patient',
        'description' => 'Before a new patient is saved, show patients with the same mobile (and a similar name). The same ID number always warns.',
        'options' => ['off' => 'Off', 'mobile' => 'Same mobile', 'mobile_name' => 'Same mobile and similar name'],
    ],
    'share_between_branches' => [
        'label' => 'Share patients between branches',
        'description' => 'A patient registered at one branch is found at every branch of the company.',
    ],
    'doctor_sees' => [
        'label' => 'Which patients doctors and nurses see',
        'description' => 'Own: people who care for patients (and do not register them) see only the patients they are linked to through a visit or a ward.',
        'options' => ['own' => 'Only their own patients', 'all' => 'All patients'],
    ],
    'portal_enabled' => [
        'label' => 'Patients in the portal',
        'description' => 'Invited patients (or a parent) see their own record and history in the portal.',
    ],
    'categories' => [
        'numbering' => 'Numbering',
        'registration' => 'Registration',
        'privacy' => 'Privacy',
    ],
];
