<?php

return [
    'fiscal_year_start' => [
        'label' => 'Fiscal year start (MM-DD)',
        'description' => 'First day of the financial year. Applies to fiscal years added after a change.',
    ],
    'journal_approval_above' => [
        'label' => 'Journal approval above',
        'description' => 'Journal entries above this amount need a second person to approve them. Empty = never.',
    ],
    'allow_backdated_entries_days' => [
        'label' => 'Backdated entries allowed (days)',
        'description' => 'How many days back a person may date an entry.',
    ],
    'allow_future_entries_days' => [
        'label' => 'Future-dated entries allowed (days)',
        'description' => 'How many days ahead a person may date an entry. 0 = not after today.',
    ],
    'journal_number_format' => [
        'label' => 'Journal number format',
        'description' => 'How posted journals are numbered, e.g. JV-{YYYY}-{SEQ:5}. {YYYY} and {YY} are the year the fiscal year starts in, {FY} its name, {SEQ:5} the running number with 5 digits.',
    ],
    'chart_template' => [
        'label' => 'Starting chart of accounts',
        'description' => 'The list of accounts a company starts with when it sets up its books. The accounts can be changed afterwards.',
        'options' => [
            'general' => 'General business',
            'school' => 'School',
            'factory' => 'Factory',
            'retail' => 'Shop and retail',
        ],
    ],
    'require_cost_centre' => [
        'label' => 'Branch or department on every line',
        'description' => 'Every journal line must name the branch or department it belongs to.',
    ],

    'document_number_formats' => [
        'label' => 'Invoice, bill and receipt numbers',
        'description' => 'How each kind is numbered when posted, e.g. INV-{YYYY}-{SEQ:5}. One running number per kind and fiscal year.',
    ],
    'payment_terms_days' => [
        'label' => 'Payment terms (days)',
        'description' => 'Days from the invoice or bill date to its due date, unless the customer or vendor has its own terms.',
    ],
    'aging_buckets' => [
        'label' => 'Aging report columns (days overdue)',
        'description' => 'Where the aging report starts a new column, e.g. 30, 60, 90.',
    ],
    'allow_overpayment' => [
        'label' => 'Keep money not yet matched to invoices',
        'description' => 'Allow receiving or paying more than the invoices or bills chosen; the rest waits as an advance.',
    ],
    'prices_include_tax' => [
        'label' => 'Prices include tax',
        'description' => 'Prices typed on invoices and bills already include VAT (as in shops). Off: VAT is added on top.',
    ],
    'categories' => [
        'tax' => 'Tax',
        'receivables' => 'Customers and vendors',
        'approval' => 'Approvals',
        'period' => 'Periods',
        'numbering' => 'Numbering',
        'setup' => 'Setup',
    ],
];
