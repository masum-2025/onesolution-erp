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
        'modules' => ['hrm', 'attendance', 'accounting', 'crm'],
        'rules' => [],
        'role_templates' => ['administrator', 'manager', 'staff', 'sales_person', 'sales_manager'],
    ],
    [
        'key' => 'school',
        'modules' => ['education', 'hrm', 'attendance', 'payroll', 'accounting'],
        'rules' => [
            // School days start early and buses run late: a short grace period.
            ['key' => 'attendance.late_grace_minutes', 'value' => 10],
            ['key' => 'hrm.probation_days', 'value' => 180],
            ['key' => 'accounting.chart_template', 'value' => 'school'],
        ],
        'role_templates' => ['principal', 'teacher', 'office_staff', 'accountant', 'finance_approver'],
    ],
    // Other education institutions: the same module, their own structure from a preset
    // (Education > presets); the school chart of accounts fits them (fees, salaries).
    [
        'key' => 'college',
        'modules' => ['education', 'course_registration', 'hrm', 'attendance', 'payroll', 'accounting'],
        'rules' => [
            ['key' => 'hrm.probation_days', 'value' => 180],
            ['key' => 'accounting.chart_template', 'value' => 'school'],
        ],
        'role_templates' => ['principal', 'teacher', 'office_staff', 'accountant', 'finance_approver'],
    ],
    [
        'key' => 'university',
        'modules' => ['education', 'course_registration', 'hrm', 'attendance', 'payroll', 'accounting'],
        'rules' => [
            ['key' => 'hrm.probation_days', 'value' => 180],
            ['key' => 'accounting.chart_template', 'value' => 'school'],
            // Lecturers see every student of their campus; there are no class teachers.
            ['key' => 'education.teacher_scope', 'value' => 'all'],
        ],
        'role_templates' => ['registrar', 'teacher', 'office_staff', 'accountant', 'finance_approver'],
    ],
    [
        'key' => 'madrasa',
        'modules' => ['education', 'hrm', 'attendance', 'payroll', 'accounting'],
        'rules' => [
            ['key' => 'attendance.late_grace_minutes', 'value' => 10],
            ['key' => 'accounting.chart_template', 'value' => 'school'],
        ],
        'role_templates' => ['principal', 'teacher', 'office_staff', 'accountant', 'finance_approver'],
    ],
    [
        'key' => 'coaching',
        'modules' => ['education', 'hrm', 'attendance', 'accounting'],
        'rules' => [
            ['key' => 'accounting.chart_template', 'value' => 'school'],
            ['key' => 'education.teacher_scope', 'value' => 'all'],
        ],
        'role_templates' => ['administrator', 'teacher', 'office_staff', 'accountant'],
    ],
    [
        'key' => 'factory',
        'modules' => ['hrm', 'attendance', 'payroll', 'inventory', 'factory_erp', 'accounting'],
        'rules' => [
            ['key' => 'attendance.late_grace_minutes', 'value' => 5],
            ['key' => 'accounting.chart_template', 'value' => 'factory'],
        ],
        'role_templates' => ['administrator', 'manager', 'hr_officer', 'accountant', 'finance_approver', 'staff'],
    ],
    [
        'key' => 'retail',
        'modules' => ['inventory', 'pos', 'crm', 'accounting', 'hrm'],
        'rules' => [
            ['key' => 'accounting.chart_template', 'value' => 'retail'],
        ],
        'role_templates' => ['administrator', 'manager', 'accountant', 'staff', 'store_keeper', 'cashier', 'shop_supervisor', 'sales_person', 'sales_manager'],
    ],
];
