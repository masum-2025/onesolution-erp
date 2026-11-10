<?php

/*
| Patients (php artisan make:erp-module database/module-definitions/patients.php).
| Only the plain patient list comes from here; the patient number, duplicate check,
| encrypted fields, "who looked" log, timeline, QR card, merge and the portal are
| written by hand in the module.
*/

$label = fn (string $en, string $bn) => ['en' => $en, 'bn' => $bn];

return [
    'key' => 'patients',
    'name' => $label('Patients', 'রোগী'),
    'description' => $label(
        'One record per patient for the whole company: patient number, contact, guardian, allergies, history and the patient portal.',
        'পুরো প্রতিষ্ঠানে প্রতিটা রোগীর একটাই record: রোগী নম্বর, যোগাযোগ, অভিভাবক, অ্যালার্জি, ইতিহাস আর রোগীর portal।'
    ),
    'requires' => [],
    'icon' => 'users',
    'entities' => [
        'patient' => [
            'label' => $label('Patient', 'রোগী'),
            'plural' => $label('Patients', 'রোগী'),
            'title' => 'name',
            'search' => ['name', 'mobile'],
            'filters' => ['sex'],
            'columns' => ['name', 'mobile'],
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max' => 150, 'label' => $label('Name', 'নাম')],
                'name_local' => ['type' => 'text', 'max' => 150, 'label' => $label('Name in Bangla', 'বাংলায় নাম')],
                'sex' => ['type' => 'enum', 'required' => true, 'label' => $label('Sex', 'লিঙ্গ'), 'options' => [
                    'male' => $label('Male', 'পুরুষ'), 'female' => $label('Female', 'নারী'), 'other' => $label('Other', 'অন্যান্য'),
                ]],
                'date_of_birth' => ['type' => 'date', 'label' => $label('Date of birth', 'জন্মতারিখ')],
                'age_years' => ['type' => 'integer', 'label' => $label('Age (years)', 'বয়স (বছর)')],
                'mobile' => ['type' => 'text', 'max' => 20, 'label' => $label('Mobile', 'মোবাইল')],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'require_id_number',
            'type' => 'boolean',
            'default' => false,
            'label' => $label('ID number required', 'পরিচয়পত্র নম্বর বাধ্যতামূলক'),
            'description' => $label('New patients cannot be saved without an ID number (NID, birth registration or passport).', 'পরিচয়পত্র নম্বর (NID, জন্মনিবন্ধন বা পাসপোর্ট) ছাড়া নতুন রোগী রাখা যাবে না।'),
        ],
    ],
];
