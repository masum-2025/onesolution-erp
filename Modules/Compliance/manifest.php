<?php

use Modules\Compliance\Connections\AuditResultHandler;
use Modules\Compliance\Connections\InvoiceHandler;
use Modules\Compliance\Connections\QuotationHandler;
use Modules\Compliance\Connections\RequestReplyHandler;
use Modules\Compliance\Dashboard\ComplianceAttention;
use Modules\Compliance\Dashboard\ExpiringSoon;
use Modules\Compliance\Dashboard\Standing;

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Compliance (CMP-1): the client's audit register. Its factories are its own
| companies and branches; each line is a factory and an audit service with
| the official last audit and expiry days. Days left are always worked out
| from the expiry day; reminders go at the rule's stages, once each.
| Lists (services, kinds, providers) and every number here are data or rules.
*/

$rule = fn (string $key, string $type, mixed $default, array $extra = []) => [
    'key' => "compliance.{$key}",
    'type' => $type,
    'default' => $default,
    'label' => "compliance::rules.{$key}.label",
    'description' => "compliance::rules.{$key}.description",
    'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
    'category' => 'compliance',
    ...$extra,
];
$names = ['type' => 'object', 'required' => ['en'], 'additionalProperties' => ['type' => 'string']];
$listSchema = fn (bool $months) => ['type' => 'array', 'maxItems' => 60, 'items' => [
    'type' => 'object', 'required' => ['code', 'name'], 'additionalProperties' => false,
    'properties' => ['code' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_.-]{1,30}$'], 'name' => $names, ...($months ? ['validity_months' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 120]] : [])],
]];

return [
    'key' => 'compliance',
    'name' => 'compliance::module.name',
    'description' => 'compliance::module.description',
    'version' => '0.1.0',
    'category' => 'operations',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    // request / confirm: renewals with the providers (CMP-2).
    // quotes: accept or decline a connected provider's quotations (a money decision).
    'permissions' => ['compliance.view', 'compliance.manage', 'compliance.set_expiry', 'compliance.request', 'compliance.confirm', 'compliance.quotes', 'compliance.bills'],
    // Providers' invoices taken into the books as bills (when Accounting is used).
    'ledger_accounts' => [
        'compliance.audit_fees' => ['label' => 'compliance::compliance.posting_keys.audit_fees', 'type' => 'expense'],
    ],
    'rules' => [
        // Days before the expiry day when the people who manage compliance are told (and once more when expired).
        $rule('reminder_days', 'json', [60, 30, 15, 7], ['schema' => ['type' => 'array', 'maxItems' => 10, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365]], 'sort_order' => 10]),
        // An audit shows as "expiring" this many days before its expiry day.
        $rule('expiring_days', 'integer', 60, ['schema' => ['minimum' => 1, 'maximum' => 365], 'sort_order' => 20]),
        // "Add the usual ones": placeholders to review per country with the client's compliance people.
        $rule('default_services', 'json', [
            ['code' => 'BSCI', 'name' => ['en' => 'amfori BSCI', 'bn' => 'অ্যামফোরি বিএসসিআই'], 'validity_months' => 12],
            ['code' => 'WRAP', 'name' => ['en' => 'WRAP', 'bn' => 'র‍্যাপ'], 'validity_months' => 12],
            ['code' => 'SMETA', 'name' => ['en' => 'Sedex SMETA', 'bn' => 'সেডেক্স স্মেটা'], 'validity_months' => 12],
            ['code' => 'ISO9001', 'name' => ['en' => 'ISO 9001', 'bn' => 'আইএসও ৯০০১'], 'validity_months' => 36],
            ['code' => 'ISO14001', 'name' => ['en' => 'ISO 14001', 'bn' => 'আইএসও ১৪০০১'], 'validity_months' => 36],
            ['code' => 'ISO45001', 'name' => ['en' => 'ISO 45001', 'bn' => 'আইএসও ৪৫০০১'], 'validity_months' => 36],
            ['code' => 'HIGG_FEM', 'name' => ['en' => 'Higg FEM', 'bn' => 'হিগ এফইএম'], 'validity_months' => 12],
            ['code' => 'HIGG_FSLM', 'name' => ['en' => 'Higg FSLM', 'bn' => 'হিগ এফএসএলএম'], 'validity_months' => 12],
            ['code' => 'OEKO_TEX', 'name' => ['en' => 'OEKO-TEX Standard 100', 'bn' => 'ওকো-টেক্স স্ট্যান্ডার্ড ১০০'], 'validity_months' => 12],
            ['code' => 'GOTS', 'name' => ['en' => 'GOTS', 'bn' => 'জিওটিএস'], 'validity_months' => 12],
            ['code' => 'FIRE', 'name' => ['en' => 'Fire licence', 'bn' => 'ফায়ার লাইসেন্স'], 'validity_months' => 12],
        ], ['schema' => $listSchema(true), 'country_specific' => true, 'sort_order' => 30]),
        $rule('default_audit_types', 'json', [
            ['code' => 'initial', 'name' => ['en' => 'Initial', 'bn' => 'প্রাথমিক']],
            ['code' => 'renewal', 'name' => ['en' => 'Renewal', 'bn' => 'নবায়ন']],
            ['code' => 'surveillance', 'name' => ['en' => 'Surveillance', 'bn' => 'নজরদারি']],
            ['code' => 'follow_up', 'name' => ['en' => 'Follow-up', 'bn' => 'ফলো-আপ']],
            ['code' => 'unannounced', 'name' => ['en' => 'Unannounced', 'bn' => 'আকস্মিক']],
        ], ['schema' => $listSchema(false), 'sort_order' => 40]),
        $rule('import_max_rows', 'integer', 2000, ['schema' => ['minimum' => 10, 'maximum' => 20000], 'sort_order' => 50]),
        // Renewals (CMP-2): when a request to the provider is due, whether it goes by itself, and
        // whether a connected provider's result enters the register without a person accepting it.
        $rule('request_days_before', 'integer', 60, ['schema' => ['minimum' => 1, 'maximum' => 365], 'sort_order' => 60]),
        $rule('auto_request', 'boolean', false, ['sort_order' => 70]),
        $rule('auto_apply_results', 'boolean', false, ['sort_order' => 80]),
    ],
    // What connected providers send back (CMP-2): kinds named after the provider's module.
    'connection_kinds' => [RequestReplyHandler::class, AuditResultHandler::class, QuotationHandler::class, InvoiceHandler::class],
    'menu' => [
        [
            'key' => 'compliance',
            'label' => 'compliance::module.menu',
            'route' => '/compliance',
            'icon' => 'shield',
            'order' => 40,
            'section' => 'business',
            'permission' => 'compliance.view',
            'children' => [
                ['key' => 'register', 'label' => 'compliance::module.menu_register', 'route' => '/compliance', 'permission' => 'compliance.view'],
                ['key' => 'renewals', 'label' => 'compliance::module.menu_renewals', 'route' => '/compliance/renewals', 'permission' => 'compliance.view'],
                ['key' => 'import', 'label' => 'compliance::module.menu_import', 'route' => '/compliance/import', 'permission' => 'compliance.set_expiry'],
            ],
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'attention' => [ComplianceAttention::class],
    'dashboard' => ['widgets' => [
        ['key' => 'standing', 'label' => 'compliance::dashboard.standing', 'type' => 'bars', 'provider' => Standing::class, 'permission' => 'compliance.view', 'size' => 2, 'overview' => true],
        ['key' => 'expiring', 'label' => 'compliance::dashboard.expiring', 'type' => 'list', 'provider' => ExpiringSoon::class, 'permission' => 'compliance.view', 'overview' => true],
    ]],
    'settings' => ['pages' => [
        ['key' => 'lists', 'label' => 'compliance::module.menu_lists', 'route' => '/compliance/lists', 'permission' => 'compliance.view'],
    ]],
    'notifications' => [
        'compliance.expiring' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'count', 'audits', 'link'],
            'audience' => 'client',
            'path' => '/compliance',
        ],
        'compliance.result_received' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'service', 'link'],
            'audience' => 'client',
            'path' => '/compliance/renewals',
        ],
        'compliance.quote_received' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'number', 'link'],
            'audience' => 'client',
            'path' => '/compliance/renewals',
        ],
        'compliance.invoice_received' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'number', 'link'],
            'audience' => 'client',
            'path' => '/compliance/renewals',
        ],
        'compliance.renewal_answered' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'service', 'status', 'planned', 'link'],
            'audience' => 'client',
            'path' => '/compliance/renewals',
        ],
    ],
];
