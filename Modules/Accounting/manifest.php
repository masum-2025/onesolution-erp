<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('accounting.*')).
*/

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
    ],
    'separation_of_duties' => [['accounting.post', 'accounting.approve']],
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
    ],
    'menu' => [
        [
            'key' => 'accounting',
            'label' => 'accounting::module.menu',
            'route' => '/accounting',
            'icon' => 'book',
            'order' => 40,
        ],
    ],
    // Other modules listen to these; payloads carry ids only.
    'events' => ['accounting.journal.posted'],
    // Accounts Accounting itself posts to (year-end closing and opening balances, ACC-4).
    'ledger_accounts' => [
        'accounting.retained_earnings' => ['label' => 'accounting::accounting.posting_keys.retained_earnings', 'type' => 'equity'],
        'accounting.opening_balance' => ['label' => 'accounting::accounting.posting_keys.opening_balance', 'type' => 'equity'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the Accounting screens (ACC-2); its rules show already.
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];
