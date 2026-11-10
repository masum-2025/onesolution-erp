<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers live in rules, never here. Other modules reach patients
| only through Modules\Patients\Contracts\PatientDirectory.
*/

use Modules\Patients\Dashboard\PatientsWidgets;
use Modules\Patients\Portal\PatientSubjects;

$rule = fn (string $name, string $type, mixed $default, array $levels, string $category, int $order, array $extra = []) => [
    'key' => "patients.{$name}",
    'type' => $type,
    'default' => $default,
    'label' => "patients::rules.{$name}.label",
    'description' => "patients::rules.{$name}.description",
    'overridable_levels' => $levels,
    'category' => $category,
    'sort_order' => $order,
    ...$extra,
];
$toCompany = ['platform', 'partner', 'group', 'company'];

return [
    'key' => 'patients',
    'name' => 'patients::module.name',
    'description' => 'patients::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => [],
    // Any sector may turn it on; the diagnostic and hospital packages (H-5) turn it on by default.
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['patients.view', 'patients.register', 'patients.manage', 'patients.treat', 'patients.audit'],
    'rules' => [
        $rule('mrn_prefix', 'string', 'P', $toCompany, 'numbering', 10, ['schema' => ['minLength' => 0, 'maxLength' => 6, 'pattern' => '^[A-Za-z0-9-]*$']]),
        $rule('require_id_number', 'boolean', false, $toCompany, 'registration', 20, ['country_specific' => true]),
        $rule('duplicate_check', 'enum', 'mobile_name', $toCompany, 'registration', 30, ['schema' => ['enum' => ['off', 'mobile', 'mobile_name']]]),
        $rule('share_between_branches', 'boolean', true, $toCompany, 'privacy', 40),
        $rule('doctor_sees', 'enum', 'all', $toCompany, 'privacy', 50, ['schema' => ['enum' => ['own', 'all']], 'sensitive' => true]),
        $rule('portal_enabled', 'boolean', true, $toCompany, 'privacy', 60),
    ],
    'menu' => [
        [
            'key' => 'patients',
            'label' => 'patients::module.menu',
            'route' => '/patients/find',
            'icon' => 'users',
            'order' => 57,
            'section' => 'business',
            'children' => [
                ['key' => 'find', 'label' => 'patients::module.menu_find', 'route' => '/patients/find', 'permission' => 'patients.view'],
                ['key' => 'merges', 'label' => 'patients::module.menu_merges', 'route' => '/patients/merges', 'permission' => 'patients.manage'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'new_patient', 'label' => 'patients::module.new_patient', 'route' => '/patients/find?new=1', 'permission' => 'patients.register', 'icon' => 'user-plus'],
    ],
    // Payloads carry ids only (never a patient's name, number or contact).
    'events' => ['patients.registered', 'patients.merged'],
    'portal_subjects' => [PatientSubjects::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'registered_today', 'label' => 'patients::dashboard.registered_today', 'type' => 'stat', 'provider' => PatientsWidgets::class, 'permission' => 'patients.view'],
    ]],
    'attention' => [PatientsWidgets::class],
    'settings' => ['pages' => []],
];
