<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\Crm\Dashboard\CrmWidgets;

return [
    'key' => 'crm',
    'name' => 'crm::module.name',
    'description' => 'crm::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Works alone; uses Inventory items, Accounting VAT and invoices, and POS sales when they are on.
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['crm.view', 'crm.edit', 'crm.manage', 'crm.export'],
    'rules' => [
        [
            'key' => 'crm.duplicate_match',
            'type' => 'enum',
            'schema' => ['enum' => ['phone', 'phone_email']],
            'default' => 'phone',
            'label' => 'crm::rules.duplicate_match.label',
            'description' => 'crm::rules.duplicate_match.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'contacts',
            'sort_order' => 10,
        ],
        [
            'key' => 'crm.marketing_consent_required',
            'type' => 'boolean',
            'default' => true,
            'label' => 'crm::rules.marketing_consent_required.label',
            'description' => 'crm::rules.marketing_consent_required.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'sensitive' => true,
            'category' => 'contacts',
            'sort_order' => 20,
        ],
        [
            'key' => 'crm.follow_up_reminder_minutes',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 1440],
            'default' => 30,
            'label' => 'crm::rules.follow_up_reminder_minutes.label',
            'description' => 'crm::rules.follow_up_reminder_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'follow_ups',
            'sort_order' => 30,
        ],
        [
            'key' => 'crm.loyalty_points_per_100',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 1000],
            'default' => 0,
            'label' => 'crm::rules.loyalty_points_per_100.label',
            'description' => 'crm::rules.loyalty_points_per_100.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'loyalty',
            'sort_order' => 40,
        ],
        [
            'key' => 'crm.quote_valid_days',
            'type' => 'integer',
            'schema' => ['minimum' => 0, 'maximum' => 365],
            'default' => 30,
            'label' => 'crm::rules.quote_valid_days.label',
            'description' => 'crm::rules.quote_valid_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'quotes',
            'sort_order' => 50,
        ],
        [
            'key' => 'crm.quote_prices_include_tax',
            'type' => 'boolean',
            'default' => false,
            'label' => 'crm::rules.quote_prices_include_tax.label',
            'description' => 'crm::rules.quote_prices_include_tax.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'country_specific' => true,
            'category' => 'quotes',
            'sort_order' => 60,
        ],
        [
            'key' => 'crm.number_prefixes',
            'type' => 'json',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'estimate' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$'],
                    'quotation' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,6}$'],
                ],
                'additionalProperties' => false,
            ],
            'default' => ['estimate' => 'EST', 'quotation' => 'QT'],
            'label' => 'crm::rules.number_prefixes.label',
            'description' => 'crm::rules.number_prefixes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'numbering',
            'sort_order' => 70,
        ],
    ],
    'menu' => [
        [
            'key' => 'crm',
            'label' => 'crm::module.menu',
            'route' => '/crm',
            'icon' => 'handshake',
            'order' => 60,
            'section' => 'business',
            'children' => [
                ['key' => 'contacts', 'label' => 'crm::module.menu_contacts', 'route' => '/crm/contacts', 'permission' => 'crm.view'],
                ['key' => 'deals', 'label' => 'crm::module.menu_deals', 'route' => '/crm/deals', 'permission' => 'crm.view'],
                ['key' => 'tasks', 'label' => 'crm::module.menu_tasks', 'route' => '/crm/tasks', 'permission' => 'crm.view'],
                ['key' => 'quotes', 'label' => 'crm::module.menu_quotes', 'route' => '/crm/quotes', 'permission' => 'crm.view'],
            ],
        ],
    ],
    'events' => [],
    // Quotations accepted become invoices on this income account (Accounting maps it).
    'ledger_accounts' => [
        'crm.sales' => ['label' => 'crm::crm.posting_keys.sales', 'type' => 'income'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'pipeline', 'label' => 'crm::dashboard.pipeline', 'type' => 'stat', 'provider' => CrmWidgets::class, 'permission' => 'crm.view', 'overview' => true],
    ]],
    'attention' => [CrmWidgets::class],
    // Messages CRM sends (wording in crm::notifications; partners may reword them).
    'notifications' => [
        'crm.follow_up_due' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'count', 'tasks', 'link'],
            'audience' => 'client',
            'path' => '/crm/tasks',
        ],
    ],
    'settings' => ['pages' => [
        ['key' => 'pipelines', 'label' => 'crm::module.menu_pipelines', 'route' => '/crm/pipelines', 'permission' => 'crm.manage'],
        ['key' => 'fields', 'label' => 'crm::module.menu_fields', 'route' => '/crm/fields', 'permission' => 'crm.manage'],
    ]],
];
