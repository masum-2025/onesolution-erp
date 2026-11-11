<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers live in rules, never here. Patients come from the Patients
| directory; fees go on the patient's bill through Patient billing's PatientCharges.
*/

return [
    'key' => 'outpatient',
    'name' => 'outpatient::module.name',
    'description' => 'outpatient::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['patients', 'patient_billing'],
    // Any sector may turn it on; the hospital and diagnostic packages (H-5) turn it on by default.
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['outpatient.view', 'outpatient.book', 'outpatient.consult', 'outpatient.manage'],
    'rules' => [
        [
            'key' => 'outpatient.daily_serial_limit',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 500],
            'default' => 40,
            'label' => 'outpatient::rules.daily_serial_limit.label',
            'description' => 'outpatient::rules.daily_serial_limit.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'serials',
            'sort_order' => 10,
        ],
        [
            'key' => 'outpatient.follow_up_free_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 90],
            'default' => 7,
            'label' => 'outpatient::rules.follow_up_free_days.label',
            'description' => 'outpatient::rules.follow_up_free_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'fees',
            'sort_order' => 20,
        ],
        [
            'key' => 'outpatient.serial_sms',
            'type' => 'boolean',
            'default' => false,
            'label' => 'outpatient::rules.serial_sms.label',
            'description' => 'outpatient::rules.serial_sms.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'serials',
            'sort_order' => 30,
        ],
        [
            'key' => 'outpatient.prescription_language',
            'type' => 'enum',
            'schema' => ['enum' => ['bn', 'en', 'both']],
            'default' => 'bn',
            'label' => 'outpatient::rules.prescription_language.label',
            'description' => 'outpatient::rules.prescription_language.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'prescriptions',
            'sort_order' => 40,
        ],
    ],
    'menu' => [
        [
            'key' => 'outpatient',
            'label' => 'outpatient::module.menu',
            'route' => '/outpatient/serials',
            'icon' => 'stethoscope',
            'order' => 58,
            'section' => 'business',
            'children' => [
                ['key' => 'serials', 'label' => 'outpatient::module.menu_serials', 'route' => '/outpatient/serials', 'permission' => 'outpatient.view'],
                ['key' => 'book', 'label' => 'outpatient::module.menu_book', 'route' => '/outpatient/book', 'permission' => 'outpatient.book'],
                ['key' => 'mine', 'label' => 'outpatient::module.menu_mine', 'route' => '/outpatient/my-patients', 'permission' => 'outpatient.consult'],
                ['key' => 'doctors', 'label' => 'outpatient::module.menu_doctors', 'route' => '/outpatient/doctors', 'permission' => 'outpatient.view'],
                ['key' => 'chambers', 'label' => 'outpatient::module.menu_chambers', 'route' => '/outpatient/chambers', 'permission' => 'outpatient.view'],
                ['key' => 'schedules', 'label' => 'outpatient::module.menu_schedules', 'route' => '/outpatient/schedules', 'permission' => 'outpatient.view'],
                ['key' => 'templates', 'label' => 'outpatient::module.menu_templates', 'route' => '/outpatient/templates', 'permission' => 'outpatient.consult'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'book', 'label' => 'outpatient::module.new_serial', 'route' => '/outpatient/book', 'permission' => 'outpatient.book', 'icon' => 'stethoscope'],
    ],
    // Payloads carry ids only (never what the doctor wrote).
    'events' => ['outpatient.serial_booked', 'outpatient.consultation_done', 'outpatient.prescription_written', 'outpatient.tests_advised'],
    // To patients (wording in outpatient::notifications).
    'notifications' => [
        'outpatient.serial_booked' => ['channels' => ['sms'], 'placeholders' => ['product', 'organization', 'doctor', 'date', 'time', 'number', 'chamber'], 'audience' => 'person', 'path' => '/'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
