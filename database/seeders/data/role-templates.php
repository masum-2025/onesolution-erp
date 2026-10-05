<?php

/*
|--------------------------------------------------------------------------
| Role templates (data, not code)
|--------------------------------------------------------------------------
|
| Organizations clone these into their own roles and adjust them. Patterns:
| "payroll.*", "*.view", "!payroll.approve" (remove). Permissions of modules
| that do not exist yet are simply skipped. A template never holds both
| permissions of a separation-of-duties pair (checked when cloning).
|
| sector: null = every sector; otherwise a sector key (e.g. school).
| Names and descriptions: lang/{locale}/access.php "templates.{key}".
|
*/

return [

    // ── Every sector ─────────────────────────────────────────────────────
    [
        'key' => 'administrator',
        'sector' => null,
        'permissions' => [
            'organizations.*', 'members.manage', 'roles.manage', 'modules.manage', 'rules.edit.*',
            '*.view', '*.manage', '*.use', '*.export',
            // Phase 8-1: ask for / approve a member's two-step sign-in reset (two different admins).
            'security.mfa_reset',
        ],
    ],
    [
        'key' => 'manager',
        'sector' => null,
        'permissions' => ['*.view', '*.manage', '*.use', '*.export', '!modules.manage', '!roles.manage', '!members.manage', '!organizations.manage'],
    ],
    [
        'key' => 'staff',
        'sector' => null,
        'permissions' => ['*.view', '*.use'],
    ],
    [
        'key' => 'accountant',
        'sector' => null,
        'permissions' => ['accounting.view', 'accounting.post', 'accounting.manage', 'accounting.sell', 'accounting.buy', 'accounting.tax', 'accounting.reconcile', 'payroll.view', 'payroll.run', 'custom_reports.view'],
    ],
    [
        'key' => 'finance_approver',
        'sector' => null,
        'permissions' => ['accounting.view', 'accounting.approve', 'accounting.close', 'payroll.view', 'payroll.approve', 'inventory.view', 'inventory.approve', 'rules.approve', 'custom_reports.view'],
    ],
    [
        'key' => 'hr_officer',
        'sector' => null,
        'permissions' => ['hrm.*', 'attendance.*', 'payroll.view', 'members.manage'],
    ],
    [
        'key' => 'store_keeper',
        'sector' => null,
        'permissions' => ['inventory.view', 'inventory.manage'],
    ],
    [
        'key' => 'cashier',
        'sector' => null,
        'permissions' => ['pos.view', 'pos.sell', 'inventory.view'],
    ],
    [
        'key' => 'shop_supervisor',
        'sector' => null,
        'permissions' => ['pos.view', 'pos.supervise', 'pos.manage', 'inventory.view'],
    ],

    // ── School ───────────────────────────────────────────────────────────
    [
        'key' => 'principal',
        'sector' => 'school',
        'permissions' => ['*.view', 'hrm.manage', 'attendance.manage', 'members.manage', 'rules.approve', 'payroll.approve', 'accounting.approve'],
    ],
    [
        'key' => 'teacher',
        'sector' => 'school',
        'permissions' => ['attendance.view', 'attendance.manage', 'hrm.view'],
    ],
    [
        'key' => 'office_staff',
        'sector' => 'school',
        'permissions' => ['*.view', 'crm.manage', 'inventory.manage', 'accounting.post', 'accounting.sell'],
    ],

];
