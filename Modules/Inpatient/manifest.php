<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Made by make:erp-module; business numbers live in rules, never here.
*/

return [
    'key' => 'inpatient',
    'name' => 'inpatient::module.name',
    'description' => 'inpatient::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['patients', 'patient_billing'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['inpatient.view', 'inpatient.admit', 'inpatient.nurse', 'inpatient.round', 'inpatient.discharge', 'inpatient.manage'],
    'rules' => [
        [
            'key' => 'inpatient.bed_day_starts',
            'type' => 'enum',
            'schema' => ['enum' => ['midnight', 'admission_time']],
            'default' => 'midnight',
            'label' => 'inpatient::rules.bed_day_starts.label',
            'description' => 'inpatient::rules.bed_day_starts.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'charges',
            'sensitive' => true,
            'sort_order' => 10,
        ],
        [
            'key' => 'inpatient.discharge_requires_clearance',
            'type' => 'boolean',
            'default' => true,
            'label' => 'inpatient::rules.discharge_requires_clearance.label',
            'description' => 'inpatient::rules.discharge_requires_clearance.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'discharge',
            'sensitive' => true,
            'sort_order' => 20,
        ],
        [
            // The advance a patient needs before admission (amount in minor units and currency); empty = none.
            'key' => 'inpatient.min_advance',
            'type' => 'money',
            'schema' => ['properties' => ['amount' => ['minimum' => 0]]],
            'nullable' => true,
            'default' => null,
            'label' => 'inpatient::rules.min_advance.label',
            'description' => 'inpatient::rules.min_advance.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'charges',
            'sort_order' => 30,
        ],
        [
            'key' => 'inpatient.number_prefix',
            'type' => 'string',
            'schema' => ['pattern' => '^[A-Za-z0-9-]{0,6}$'],
            'default' => 'IP',
            'label' => 'inpatient::rules.number_prefix.label',
            'description' => 'inpatient::rules.number_prefix.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'general',
            'sort_order' => 40,
        ],
        [
            'key' => 'inpatient.discharge_language',
            'type' => 'enum',
            'schema' => ['enum' => ['bn', 'en', 'both']],
            'default' => 'bn',
            'label' => 'inpatient::rules.discharge_language.label',
            'description' => 'inpatient::rules.discharge_language.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'discharge',
            'sort_order' => 50,
        ],
    ],
    'menu' => [
        [
            'key' => 'inpatient',
            'label' => 'inpatient::module.menu',
            'route' => '/inpatient/board',
            'icon' => 'bed',
            'order' => 59,
            'section' => 'business',
            'children' => [
                ['key' => 'admit', 'label' => 'inpatient::module.menu_admit', 'route' => '/inpatient/admit', 'permission' => 'inpatient.admit'],
                ['key' => 'board', 'label' => 'inpatient::module.menu_board', 'route' => '/inpatient/board', 'permission' => 'inpatient.view'],
                ['key' => 'admissions', 'label' => 'inpatient::module.menu_admissions', 'route' => '/inpatient/admissions', 'permission' => 'inpatient.view'],
                ['key' => 'discharges', 'label' => 'inpatient::module.menu_discharges', 'route' => '/inpatient/admissions?status=discharged', 'permission' => 'inpatient.view'],
                ['key' => 'wards', 'label' => 'inpatient::module.menu_wards', 'route' => '/inpatient/wards', 'permission' => 'inpatient.view'],
                ['key' => 'beds', 'label' => 'inpatient::module.menu_beds', 'route' => '/inpatient/beds', 'permission' => 'inpatient.manage'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'admit', 'label' => 'inpatient::module.new_admission', 'route' => '/inpatient/admit', 'permission' => 'inpatient.admit', 'icon' => 'bed'],
    ],
    // Payloads carry ids only.
    'events' => ['inpatient.admitted', 'inpatient.bed_moved', 'inpatient.tests_ordered', 'inpatient.discharged'],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
