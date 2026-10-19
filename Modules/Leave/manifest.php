<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('leave.*')).
*/

use Modules\Leave\Dashboard\OnLeaveToday;
use Modules\Leave\Dashboard\RequestsWaiting;
use Modules\Leave\Offline\LeaveRequestSync;

return [
    'key' => 'leave',
    'name' => 'leave::module.name',
    'description' => 'leave::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Employees are HRM's (EmployeeDirectory). Attendance (weekends, holidays, leave on the
    // day) and Payroll (leave without pay) work with Leave when they are on, not required.
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // See the leave of people at a unit and below.
        'leave.view',
        // Ask for one's own leave (through the login HR linked to the employee).
        'leave.request',
        // Decide a step of someone else's request (never one's own).
        'leave.approve',
        // Kinds of leave, balances by hand, asking or cancelling for someone.
        'leave.manage',
    ],
    'rules' => [
        [
            'key' => 'leave.year_start_month',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 12],
            'default' => 1,
            'label' => 'leave::rules.year_start_month.label',
            'description' => 'leave::rules.year_start_month.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'leave_year',
            'sort_order' => 10,
        ],
        [
            'key' => 'leave.approval_steps',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 3],
            'default' => 1,
            'label' => 'leave::rules.approval_steps.label',
            'description' => 'leave::rules.approval_steps.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch', 'department'],
            'category' => 'approval',
            'sort_order' => 20,
        ],
        [
            'key' => 'leave.beyond_balance',
            'type' => 'enum',
            // refuse = no more than the balance; unpaid = the rest is taken without pay.
            'schema' => ['enum' => ['refuse', 'unpaid']],
            'default' => 'refuse',
            'label' => 'leave::rules.beyond_balance.label',
            'description' => 'leave::rules.beyond_balance.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'approval',
            'sort_order' => 30,
        ],
        [
            'key' => 'leave.backdate_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 90],
            'default' => 7,
            'label' => 'leave::rules.backdate_days.label',
            'description' => 'leave::rules.backdate_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'approval',
            'sort_order' => 40,
        ],
        [
            // The usual kinds of leave a company starts from ("Add the usual ones"); per country
            // in database/seeders/data/rule-values.php (Bangladesh: Labour Act values, placeholders
            // for a legal adviser). Days here; stored as half days on each type.
            'key' => 'leave.default_types',
            'type' => 'json',
            'schema' => [
                'type' => 'array', 'maxItems' => 20,
                'items' => [
                    'type' => 'object', 'required' => ['code', 'name', 'paid', 'accrual'], 'additionalProperties' => false,
                    'properties' => [
                        'code' => ['type' => 'string', 'maxLength' => 20],
                        'name' => ['type' => 'object'],
                        'paid' => ['type' => 'boolean'],
                        'accrual' => ['enum' => ['yearly', 'monthly', 'worked_days']],
                        'days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365],
                        'worked_days_per_day' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                        'carry_max_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365],
                        'half_day' => ['type' => 'boolean'],
                        'counts_days_off' => ['type' => 'boolean'],
                        'document_after_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                        'gender' => ['enum' => ['female', 'male']],
                        'encashable' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'default' => [
                ['code' => 'CL', 'name' => ['en' => 'Casual leave', 'bn' => 'নৈমিত্তিক ছুটি'], 'paid' => true, 'accrual' => 'yearly', 'days' => 10, 'half_day' => true],
                ['code' => 'SL', 'name' => ['en' => 'Sick leave', 'bn' => 'অসুস্থতাজনিত ছুটি'], 'paid' => true, 'accrual' => 'yearly', 'days' => 10, 'half_day' => true, 'document_after_days' => 2],
                ['code' => 'AL', 'name' => ['en' => 'Annual leave', 'bn' => 'বার্ষিক ছুটি'], 'paid' => true, 'accrual' => 'monthly', 'days' => 15, 'carry_max_days' => 10, 'half_day' => true, 'encashable' => true],
                ['code' => 'LWP', 'name' => ['en' => 'Leave without pay', 'bn' => 'বিনা বেতনে ছুটি'], 'paid' => false, 'accrual' => 'yearly', 'days' => 0, 'half_day' => true],
            ],
            'label' => 'leave::rules.default_types.label',
            'description' => 'leave::rules.default_types.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'leave_year',
            'sort_order' => 50,
        ],
    ],
    'menu' => [
        [
            'key' => 'leave',
            'label' => 'leave::module.menu',
            'route' => '/leave/me',
            'icon' => 'calendar-off',
            'order' => 22,
            'section' => 'people',
            'children' => [
                ['key' => 'me', 'label' => 'leave::module.menu_me', 'route' => '/leave/me', 'permission' => 'leave.request'],
                ['key' => 'requests', 'label' => 'leave::module.menu_requests', 'route' => '/leave/requests', 'permission' => 'leave.view'],
                ['key' => 'calendar', 'label' => 'leave::module.menu_calendar', 'route' => '/leave/calendar', 'permission' => 'leave.view'],
                ['key' => 'balances', 'label' => 'leave::module.menu_balances', 'route' => '/leave/balances', 'permission' => 'leave.view'],
                ['key' => 'encashments', 'label' => 'leave::module.menu_encashments', 'route' => '/leave/encashments', 'permission' => 'leave.view'],
            ],
        ],
    ],
    // The header "New" menu: asking for leave from anywhere.
    'quick_actions' => [
        ['key' => 'ask_leave', 'label' => 'leave::module.ask', 'route' => '/leave/me', 'permission' => 'leave.request', 'icon' => 'calendar-off'],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'on_leave', 'label' => 'leave::dashboard.on_leave', 'type' => 'stat', 'provider' => OnLeaveToday::class, 'permission' => 'leave.view', 'overview' => true],
        ['key' => 'waiting', 'label' => 'leave::dashboard.waiting', 'type' => 'stat', 'provider' => RequestsWaiting::class, 'permission' => 'leave.approve'],
    ]],
    'attention' => [RequestsWaiting::class],
    'notifications' => [
        'leave.requested' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'employee', 'type', 'dates', 'days', 'link'],
            'audience' => 'client',
            'path' => '/leave/requests',
        ],
        'leave.decided' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'employee', 'type', 'dates', 'days', 'decision', 'note', 'link'],
            'audience' => 'client',
            'path' => '/leave/me',
        ],
    ],
    // Asking for leave while the phone is offline (Offline mode on).
    'sync_records' => [LeaveRequestSync::class],
    'settings' => ['pages' => [
        ['key' => 'types', 'label' => 'leave::module.menu_types', 'route' => '/leave/types', 'permission' => 'leave.view'],
    ]],
    // An employee opens their own leave from their record in the client's portal.
    'portal_pages' => [
        ['subject' => 'hrm.employee', 'label' => 'leave::module.portal_leave', 'route' => '/portal/leave'],
    ],
];
