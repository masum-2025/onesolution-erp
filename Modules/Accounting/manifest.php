<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('accounting.*')).
*/

use Modules\Accounting\Dashboard\CustomersOverdue;
use Modules\Accounting\Dashboard\CustomersOwe;
use Modules\Accounting\Dashboard\ExpensesThisMonth;
use Modules\Accounting\Dashboard\IncomeThisMonth;
use Modules\Accounting\Dashboard\RecentJournals;
use Modules\Accounting\Dashboard\VendorsOwed;
use Modules\Accounting\Dashboard\WaitingApproval;
use Modules\Accounting\Payments\InvoiceCollectable;
use Modules\Accounting\Portal\CustomerSubjects;

return [
    'key' => 'accounting',
    'name' => 'accounting::module.name',
    'description' => 'accounting::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        'accounting.view',
        // Write, send and reverse journal entries.
        'accounting.post',
        // Approve or reject entries above the approval amount (never ones the person wrote).
        'accounting.approve',
        // Set up the books: chart of accounts, fiscal years, posting accounts.
        'accounting.manage',
        // Close and reopen periods.
        'accounting.close',
        // Customers, invoices, credit notes and money received.
        'accounting.sell',
        // Vendors, bills, vendor credits and money paid.
        'accounting.buy',
    ],
    'separation_of_duties' => [
        ['accounting.post', 'accounting.approve'],
        ['accounting.sell', 'accounting.approve'],
        ['accounting.buy', 'accounting.approve'],
    ],
    'rules' => [
        [
            'key' => 'accounting.fiscal_year_start',
            'type' => 'date',
            'schema' => ['pattern' => '^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$'],
            'default' => '01-01',
            'label' => 'accounting::rules.fiscal_year_start.label',
            'description' => 'accounting::rules.fiscal_year_start.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'sensitive' => true,
            'category' => 'period',
            'sort_order' => 10,
        ],
        [
            'key' => 'accounting.journal_approval_above',
            'type' => 'money',
            'schema' => ['properties' => ['amount' => ['minimum' => 0]]],
            'nullable' => true,
            'default' => null,
            'label' => 'accounting::rules.journal_approval_above.label',
            'description' => 'accounting::rules.journal_approval_above.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'approval',
            'sort_order' => 20,
        ],
        [
            'key' => 'accounting.allow_backdated_entries_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 366],
            'default' => 7,
            'label' => 'accounting::rules.allow_backdated_entries_days.label',
            'description' => 'accounting::rules.allow_backdated_entries_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'period',
            'sort_order' => 30,
        ],
        [
            'key' => 'accounting.allow_future_entries_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 366],
            'default' => 0,
            'label' => 'accounting::rules.allow_future_entries_days.label',
            'description' => 'accounting::rules.allow_future_entries_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'sensitive' => true,
            'category' => 'period',
            'sort_order' => 35,
        ],
        [
            'key' => 'accounting.journal_number_format',
            'type' => 'string',
            // Placeholders: {YYYY} {YY} (the year the fiscal year starts in), {FY} (its name), {SEQ:n}
            'schema' => ['minLength' => 3, 'maxLength' => 50, 'pattern' => '^[A-Za-z0-9{}:_/-]*\{SEQ(:\d+)?\}[A-Za-z0-9{}:_/-]*$'],
            'default' => 'JV-{YYYY}-{SEQ:5}',
            'label' => 'accounting::rules.journal_number_format.label',
            'description' => 'accounting::rules.journal_number_format.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 40,
        ],
        [
            'key' => 'accounting.chart_template',
            'type' => 'enum',
            // Files in database/data/charts; a sector package suggests its own.
            'schema' => ['enum' => ['general', 'school', 'factory', 'retail']],
            'default' => 'general',
            'label' => 'accounting::rules.chart_template.label',
            'description' => 'accounting::rules.chart_template.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'setup',
            'sort_order' => 50,
        ],
        [
            'key' => 'accounting.require_cost_centre',
            'type' => 'boolean',
            'default' => false,
            'label' => 'accounting::rules.require_cost_centre.label',
            'description' => 'accounting::rules.require_cost_centre.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'setup',
            'sort_order' => 60,
        ],
        [
            'key' => 'accounting.document_number_formats',
            'type' => 'json',
            // Placeholders as for journals: {YYYY} {YY} {FY} {SEQ:n}; one running number per kind and fiscal year.
            'schema' => [
                'type' => 'object',
                'required' => ['invoice', 'credit_note', 'bill', 'vendor_credit', 'receipt', 'payment'],
                'additionalProperties' => false,
                'properties' => array_fill_keys(
                    ['invoice', 'credit_note', 'bill', 'vendor_credit', 'receipt', 'payment'],
                    ['type' => 'string', 'minLength' => 3, 'maxLength' => 50, 'pattern' => '^[A-Za-z0-9{}:_/-]*\{SEQ(:\d+)?\}[A-Za-z0-9{}:_/-]*$'],
                ),
            ],
            'default' => [
                'invoice' => 'INV-{YYYY}-{SEQ:5}',
                'credit_note' => 'CN-{YYYY}-{SEQ:5}',
                'bill' => 'BILL-{YYYY}-{SEQ:5}',
                'vendor_credit' => 'VC-{YYYY}-{SEQ:5}',
                'receipt' => 'RCPT-{YYYY}-{SEQ:5}',
                'payment' => 'PAY-{YYYY}-{SEQ:5}',
            ],
            'label' => 'accounting::rules.document_number_formats.label',
            'description' => 'accounting::rules.document_number_formats.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 45,
        ],
        [
            'key' => 'accounting.payment_terms_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 365],
            'default' => 30,
            'label' => 'accounting::rules.payment_terms_days.label',
            'description' => 'accounting::rules.payment_terms_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'country_specific' => true,
            'category' => 'receivables',
            'sort_order' => 70,
        ],
        [
            'key' => 'accounting.aging_buckets',
            'type' => 'json',
            // Days overdue where the aging report starts a new column, e.g. [30, 60, 90].
            'schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730], 'minItems' => 1, 'maxItems' => 6, 'uniqueItems' => true],
            'default' => [30, 60, 90],
            'label' => 'accounting::rules.aging_buckets.label',
            'description' => 'accounting::rules.aging_buckets.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'receivables',
            'sort_order' => 80,
        ],
        [
            'key' => 'accounting.allow_overpayment',
            'type' => 'boolean',
            'default' => false,
            'label' => 'accounting::rules.allow_overpayment.label',
            'description' => 'accounting::rules.allow_overpayment.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'receivables',
            'sort_order' => 90,
        ],
    ],
    'menu' => [
        [
            'key' => 'accounting',
            'label' => 'accounting::module.menu',
            'route' => '/accounting',
            'icon' => 'book',
            'order' => 40,
            'section' => 'finance',
            'children' => [
                ['key' => 'journals', 'label' => 'accounting::module.menu_journals', 'route' => '/accounting', 'permission' => 'accounting.view'],
                ['key' => 'approvals', 'label' => 'accounting::module.menu_approvals', 'route' => '/accounting/approvals', 'permission' => 'accounting.approve'],
                ['key' => 'accounts', 'label' => 'accounting::module.menu_accounts', 'route' => '/accounting/accounts', 'permission' => 'accounting.view'],
                ['key' => 'reports', 'label' => 'accounting::module.menu_reports', 'route' => '/accounting/reports', 'permission' => 'accounting.view'],
            ],
        ],
        [
            'key' => 'sales',
            'label' => 'accounting::module.menu_sales',
            'route' => '/accounting/sales',
            'icon' => 'receipt',
            'order' => 41,
            'section' => 'finance',
            'children' => [
                ['key' => 'customers', 'label' => 'accounting::module.menu_customers', 'route' => '/accounting/customers', 'permission' => 'accounting.view'],
                ['key' => 'invoices', 'label' => 'accounting::module.menu_invoices', 'route' => '/accounting/sales', 'permission' => 'accounting.view'],
                ['key' => 'receipts', 'label' => 'accounting::module.menu_receipts', 'route' => '/accounting/receipts', 'permission' => 'accounting.view'],
            ],
        ],
        [
            'key' => 'purchases',
            'label' => 'accounting::module.menu_purchases',
            'route' => '/accounting/purchases',
            'icon' => 'cart',
            'order' => 42,
            'section' => 'finance',
            'children' => [
                ['key' => 'vendors', 'label' => 'accounting::module.menu_vendors', 'route' => '/accounting/vendors', 'permission' => 'accounting.view'],
                ['key' => 'bills', 'label' => 'accounting::module.menu_bills', 'route' => '/accounting/purchases', 'permission' => 'accounting.view'],
                ['key' => 'payments', 'label' => 'accounting::module.menu_payments', 'route' => '/accounting/payments', 'permission' => 'accounting.view'],
            ],
        ],
    ],
    // The module's dashboard; `overview` widgets also show on the main overview.
    'dashboard' => ['widgets' => [
        ['key' => 'income', 'label' => 'accounting::dashboard.income', 'type' => 'stat', 'provider' => IncomeThisMonth::class, 'permission' => 'accounting.view', 'overview' => true],
        ['key' => 'expenses', 'label' => 'accounting::dashboard.expenses', 'type' => 'stat', 'provider' => ExpensesThisMonth::class, 'permission' => 'accounting.view', 'overview' => true],
        ['key' => 'customers_owe', 'label' => 'accounting::dashboard.customers_owe', 'type' => 'stat', 'provider' => CustomersOwe::class, 'permission' => 'accounting.view', 'overview' => true],
        ['key' => 'customers_overdue', 'label' => 'accounting::dashboard.customers_overdue', 'type' => 'stat', 'provider' => CustomersOverdue::class, 'permission' => 'accounting.view'],
        ['key' => 'vendors_owed', 'label' => 'accounting::dashboard.vendors_owed', 'type' => 'stat', 'provider' => VendorsOwed::class, 'permission' => 'accounting.view'],
        ['key' => 'waiting', 'label' => 'accounting::dashboard.waiting', 'type' => 'stat', 'provider' => WaitingApproval::class, 'permission' => 'accounting.view'],
        ['key' => 'recent', 'label' => 'accounting::dashboard.recent', 'type' => 'list', 'provider' => RecentJournals::class, 'permission' => 'accounting.view', 'size' => 2],
    ]],
    // Work waiting in the header bell: entries to approve (for approvers).
    'attention' => [WaitingApproval::class],
    // Setup screens shown on the module's settings page (next to its rules).
    'settings' => ['pages' => [
        ['key' => 'setup', 'label' => 'accounting::module.menu_setup', 'route' => '/accounting/setup', 'permission' => 'accounting.manage'],
        ['key' => 'accounts', 'label' => 'accounting::module.menu_accounts', 'route' => '/accounting/accounts', 'permission' => 'accounting.view'],
        ['key' => 'fiscal_years', 'label' => 'accounting::module.menu_fiscal_years', 'route' => '/accounting/fiscal-years', 'permission' => 'accounting.view'],
        ['key' => 'posting_accounts', 'label' => 'accounting::module.menu_posting_accounts', 'route' => '/accounting/posting-accounts', 'permission' => 'accounting.view'],
    ]],
    // The header "New" menu.
    'quick_actions' => [
        ['key' => 'invoice', 'label' => 'accounting::module.new_invoice', 'route' => '/accounting/documents/new?type=invoice', 'permission' => 'accounting.sell', 'icon' => 'receipt'],
        ['key' => 'journal', 'label' => 'accounting::module.new_journal', 'route' => '/accounting/journals/new', 'permission' => 'accounting.post', 'icon' => 'file-plus'],
    ],
    // Other modules listen to these; payloads carry ids only.
    'events' => ['accounting.journal.posted'],
    // Accounts Accounting itself posts to (year-end closing and opening balances, ACC-4).
    'ledger_accounts' => [
        'accounting.retained_earnings' => ['label' => 'accounting::accounting.posting_keys.retained_earnings', 'type' => 'equity'],
        'accounting.opening_balance' => ['label' => 'accounting::accounting.posting_keys.opening_balance', 'type' => 'equity'],
        // What customers owe and what is owed to vendors (invoices, bills and the money that pays them).
        'accounting.receivable' => ['label' => 'accounting::accounting.posting_keys.receivable', 'type' => 'asset'],
        'accounting.payable' => ['label' => 'accounting::accounting.posting_keys.payable', 'type' => 'liability'],
        // Where money customers pay online arrives (the gateway's settlement account).
        'accounting.online_collections' => ['label' => 'accounting::accounting.posting_keys.online_collections', 'type' => 'asset'],
    ],
    // A customer sees their own invoices in the client's portal, and pays them online.
    'portal_subjects' => [CustomerSubjects::class],
    'payment_collectables' => [InvoiceCollectable::class],
    'is_core' => false,
    'requires_consent' => false,
];
