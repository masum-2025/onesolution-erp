<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('expenses.*')).
*/

use Modules\Expenses\Dashboard\ClaimsWaiting;
use Modules\Expenses\Dashboard\SpentThisMonth;

// A rule of money: minor units of the company's currency (50000 = 500.00).
$money = fn (string $key, int $default, int $order) => [
    'key' => "expenses.{$key}",
    'type' => 'integer',
    'schema' => ['minimum' => 0, 'maximum' => 100000000000],
    'default' => $default,
    'label' => "expenses::rules.{$key}.label",
    'description' => "expenses::rules.{$key}.description",
    'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
    'category' => 'expenses',
    'sort_order' => $order,
];

return [
    'key' => 'expenses',
    'name' => 'expenses::module.name',
    'description' => 'expenses::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Employees are HRM's (EmployeeDirectory). Accounting (the books) and Payroll (paid with
    // the salary) work with Expenses when they are on, not required.
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // Claim one's own expenses (through the login HR linked to the employee).
        'expenses.claim',
        // Decide the first step of claims of people at a unit and below (never one's own).
        'expenses.approve',
        // Decide the second step and pay claims back (cash, bank, with the salary).
        'expenses.pay',
        // Kinds of expense; see every claim; decide the first step for anyone.
        'expenses.manage',
    ],
    'rules' => [
        // PLACEHOLDERS: amounts for Bangladesh taka until the business confirms them.
        $money('receipt_required_above', 50000, 10),
        $money('second_approval_above', 500000, 20),
        [
            'key' => 'expenses.max_claim_age_days',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 366],
            'default' => 60,
            'label' => 'expenses::rules.max_claim_age_days.label',
            'description' => 'expenses::rules.max_claim_age_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'expenses',
            'sort_order' => 30,
        ],
        // Per kilometre, minor units; 0 = no mileage claims.
        $money('mileage_rate', 0, 40),
        [
            'key' => 'expenses.receipt_max_kb',
            'type' => 'integer',
            'schema' => ['minimum' => 100, 'maximum' => 20480],
            'default' => 5120,
            'label' => 'expenses::rules.receipt_max_kb.label',
            'description' => 'expenses::rules.receipt_max_kb.description',
            'overridable_levels' => ['platform', 'partner', 'plan'],
            'category' => 'expenses',
            'sort_order' => 50,
        ],
        [
            // The usual kinds of expense "Add the usual ones" adds (per country or partner).
            'key' => 'expenses.default_categories',
            'type' => 'json',
            'schema' => [
                'type' => 'array', 'maxItems' => 30,
                'items' => [
                    'type' => 'object', 'required' => ['code', 'name', 'posting_key'], 'additionalProperties' => false,
                    'properties' => [
                        'code' => ['type' => 'string', 'maxLength' => 20],
                        'name' => ['type' => 'object'],
                        'posting_key' => ['enum' => ['expenses.travel', 'expenses.meals', 'expenses.office', 'expenses.communication', 'expenses.other']],
                        'mileage' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'default' => [
                ['code' => 'TRAVEL', 'name' => ['en' => 'Travel and transport', 'bn' => 'যাতায়াত ও পরিবহন'], 'posting_key' => 'expenses.travel'],
                ['code' => 'MEALS', 'name' => ['en' => 'Meals', 'bn' => 'খাবার'], 'posting_key' => 'expenses.meals'],
                ['code' => 'OFFICE', 'name' => ['en' => 'Office supplies', 'bn' => 'অফিস সামগ্রী'], 'posting_key' => 'expenses.office'],
                ['code' => 'PHONE', 'name' => ['en' => 'Phone and internet', 'bn' => 'মোবাইল ও ইন্টারনেট'], 'posting_key' => 'expenses.communication'],
                ['code' => 'OTHER', 'name' => ['en' => 'Other', 'bn' => 'অন্যান্য'], 'posting_key' => 'expenses.other'],
            ],
            'label' => 'expenses::rules.default_categories.label',
            'description' => 'expenses::rules.default_categories.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'expenses',
            'sort_order' => 60,
        ],
    ],
    'menu' => [
        [
            'key' => 'expenses',
            'label' => 'expenses::module.menu',
            'route' => '/expenses/me',
            'icon' => 'receipt',
            'order' => 23,
            'section' => 'people',
            'children' => [
                ['key' => 'me', 'label' => 'expenses::module.menu_me', 'route' => '/expenses/me', 'permission' => 'expenses.claim'],
                ['key' => 'approvals', 'label' => 'expenses::module.menu_approvals', 'route' => '/expenses/approvals', 'permission' => 'expenses.approve'],
                ['key' => 'claims', 'label' => 'expenses::module.menu_claims', 'route' => '/expenses/claims', 'permission' => 'expenses.pay'],
            ],
        ],
    ],
    // The header "New" menu: a claim from anywhere.
    'quick_actions' => [
        ['key' => 'claim', 'label' => 'expenses::module.new_claim', 'route' => '/expenses/me?new=1', 'permission' => 'expenses.claim', 'icon' => 'receipt'],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'waiting', 'label' => 'expenses::dashboard.waiting', 'type' => 'stat', 'provider' => ClaimsWaiting::class, 'permission' => 'expenses.approve', 'overview' => true],
        ['key' => 'spent', 'label' => 'expenses::dashboard.spent', 'type' => 'stat', 'provider' => SpentThisMonth::class, 'permission' => 'expenses.pay'],
    ]],
    'attention' => [ClaimsWaiting::class],
    'notifications' => [
        'expenses.submitted' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'employee', 'number', 'amount', 'link'],
            'audience' => 'client',
            'path' => '/expenses/approvals',
        ],
        'expenses.decided' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'employee', 'number', 'amount', 'decision', 'note', 'link'],
            'audience' => 'client',
            'path' => '/expenses/me',
        ],
        'expenses.paid' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'employee', 'number', 'amount', 'link'],
            'audience' => 'client',
            'path' => '/expenses/me',
        ],
    ],
    // Where claims go in the books (Accounting maps each key to an account).
    'ledger_accounts' => [
        'expenses.travel' => ['label' => 'expenses::expenses.posting_keys.travel', 'type' => 'expense'],
        'expenses.meals' => ['label' => 'expenses::expenses.posting_keys.meals', 'type' => 'expense'],
        'expenses.office' => ['label' => 'expenses::expenses.posting_keys.office', 'type' => 'expense'],
        'expenses.communication' => ['label' => 'expenses::expenses.posting_keys.communication', 'type' => 'expense'],
        'expenses.other' => ['label' => 'expenses::expenses.posting_keys.other', 'type' => 'expense'],
        'expenses.claims_payable' => ['label' => 'expenses::expenses.posting_keys.claims_payable', 'type' => 'liability'],
        'expenses.cash_account' => ['label' => 'expenses::expenses.posting_keys.cash_account', 'type' => 'asset'],
        'expenses.bank_account' => ['label' => 'expenses::expenses.posting_keys.bank_account', 'type' => 'asset'],
    ],
    'settings' => ['pages' => [
        ['key' => 'categories', 'label' => 'expenses::module.menu_categories', 'route' => '/expenses/categories', 'permission' => 'expenses.manage'],
    ]],
    // An employee follows their own claims from their record in the client's portal.
    'portal_pages' => [
        ['subject' => 'hrm.employee', 'label' => 'expenses::module.portal_expenses', 'route' => '/portal/expenses'],
    ],
];
