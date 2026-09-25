<?php

/*
|--------------------------------------------------------------------------
| Sector packages (data, not code)
|--------------------------------------------------------------------------
|
| What a new company of a sector starts with. Applied once when the company
| is created (ApplySectorPackage), as ordinary company-level data, so all of
| it stays editable:
|
| modules:        turned on at the company when the plan includes them;
|                 the rest are shown as "available on a higher plan".
| rules:          company-level rule values [key, value, mode = set].
| role_templates: roles cloned from database/seeders/data/role-templates.php.
| demo_seeder:    optional seeder class for local demo data.
|
| The keys are the only valid `sector_key` values. A new sector = a new entry
| here + its names in lang/{locale}/packaging.php "sectors.{key}".
|
*/

return [
    [
        'key' => 'general',
        'modules' => ['hrm', 'attendance', 'accounting'],
        'rules' => [],
        'role_templates' => ['administrator', 'manager', 'staff'],
    ],
    [
        'key' => 'school',
        'modules' => ['hrm', 'attendance', 'payroll', 'accounting'],
        'rules' => [
            // School days start early and buses run late: a short grace period.
            ['key' => 'attendance.late_grace_minutes', 'value' => 10],
            ['key' => 'hrm.probation_days', 'value' => 180],
        ],
        'role_templates' => ['principal', 'teacher', 'office_staff', 'accountant', 'finance_approver'],
    ],
    [
        'key' => 'factory',
        'modules' => ['hrm', 'attendance', 'payroll', 'inventory', 'factory_erp', 'accounting'],
        'rules' => [
            ['key' => 'attendance.late_grace_minutes', 'value' => 5],
        ],
        'role_templates' => ['administrator', 'manager', 'hr_officer', 'accountant', 'finance_approver', 'staff'],
    ],
    [
        'key' => 'retail',
        'modules' => ['inventory', 'crm', 'accounting', 'hrm'],
        'rules' => [],
        'role_templates' => ['administrator', 'manager', 'accountant', 'staff'],
    ],
];
