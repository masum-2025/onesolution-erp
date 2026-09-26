<?php

/*
| Rules owned by the platform core (tenancy and module system).
| Same format as a module manifest's `rules`. Defaults here are generic
| platform defaults; country, partner and plan values are data.
*/

return [
    [
        'key' => 'tenancy.max_depth',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 10],
        'default' => 6,
        'label' => 'rules.core.tenancy_max_depth.label',
        'description' => 'rules.core.tenancy_max_depth.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'organization',
        'sort_order' => 10,
    ],
    [
        'key' => 'tenancy.allowed_parents',
        'type' => 'json',
        'schema' => [
            'type' => 'object',
            'required' => ['group', 'company', 'branch', 'department'],
            'additionalProperties' => [
                'type' => 'array',
                'items' => ['enum' => ['root', 'group', 'company', 'branch', 'department']],
                'minItems' => 1,
            ],
        ],
        'default' => [
            'group' => ['root'],
            'company' => ['group'],
            'branch' => ['company'],
            'department' => ['branch', 'company'],
        ],
        'label' => 'rules.core.tenancy_allowed_parents.label',
        'description' => 'rules.core.tenancy_allowed_parents.description',
        'overridable_levels' => ['platform', 'partner'],
        'sensitive' => true,
        'category' => 'organization',
        'sort_order' => 20,
    ],
    [
        'key' => 'tenancy.token_ttl_minutes',
        'type' => 'integer',
        'schema' => ['minimum' => 15, 'maximum' => 10080],
        'default' => 720,
        'label' => 'rules.core.tenancy_token_ttl_minutes.label',
        'description' => 'rules.core.tenancy_token_ttl_minutes.description',
        'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
        'category' => 'security',
        'sort_order' => 30,
    ],
    [
        'key' => 'modules.purge_delay_days',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 90],
        'default' => 7,
        'label' => 'rules.core.modules_purge_delay_days.label',
        'description' => 'rules.core.modules_purge_delay_days.description',
        'overridable_levels' => ['platform', 'partner', 'group', 'company'],
        // Shortening the safety delay before data deletion needs a second person.
        'sensitive' => true,
        'category' => 'data',
        'sort_order' => 40,
    ],
    [
        'key' => 'access.separation_of_duties',
        'type' => 'table',
        // Pairs of permissions one person may not hold together (e.g. run and
        // approve payroll). The default is built from the module manifests'
        // `separation_of_duties` (RuleCatalog::fromModules).
        'schema' => [
            'items' => [
                'type' => 'object',
                'required' => ['first', 'second'],
                'properties' => [
                    'first' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$'],
                    'second' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'default' => [],
        'label' => 'rules.core.access_separation_of_duties.label',
        'description' => 'rules.core.access_separation_of_duties.description',
        'overridable_levels' => ['platform', 'partner', 'group', 'company'],
        // Weakening separation of duties needs a second person.
        'sensitive' => true,
        'category' => 'access',
        'sort_order' => 50,
    ],

    // Usage limits of a subscription (the whole tree under its top organization).
    // null = unlimited. Plan values are data (rule-values.php). A partner value is a
    // default for plans that set none (plans sit below partners in the hierarchy).
    // A per-client deal is a value at the client's top organization, written by
    // the partner console; organizations themselves can never change their limits.
    [
        'key' => 'plans.max_users',
        'type' => 'integer',
        'schema' => ['minimum' => 1],
        'nullable' => true,
        'default' => null,
        'label' => 'rules.core.plans_max_users.label',
        'description' => 'rules.core.plans_max_users.description',
        // group / company: a per-client deal, written by the partner console only.
        'overridable_levels' => ['platform', 'plan', 'partner', 'group', 'company'],
        'organization_editable' => false,
        'category' => 'plan',
        'sort_order' => 60,
    ],
    [
        'key' => 'plans.max_branches',
        'type' => 'integer',
        'schema' => ['minimum' => 0],
        'nullable' => true,
        'default' => null,
        'label' => 'rules.core.plans_max_branches.label',
        'description' => 'rules.core.plans_max_branches.description',
        // group / company: a per-client deal, written by the partner console only.
        'overridable_levels' => ['platform', 'plan', 'partner', 'group', 'company'],
        'organization_editable' => false,
        'category' => 'plan',
        'sort_order' => 61,
    ],
    [
        'key' => 'plans.max_storage_mb',
        'type' => 'integer',
        'schema' => ['minimum' => 0],
        'nullable' => true,
        'default' => null,
        'label' => 'rules.core.plans_max_storage_mb.label',
        'description' => 'rules.core.plans_max_storage_mb.description',
        // group / company: a per-client deal, written by the partner console only.
        'overridable_levels' => ['platform', 'plan', 'partner', 'group', 'company'],
        'organization_editable' => false,
        'category' => 'plan',
        'sort_order' => 62,
    ],

    // ── Partner governance: the platform sets these for a partner (rules:set
    //    --partner=...); partners see them but cannot change them. ──────────
    [
        'key' => 'partners.max_clients',
        'type' => 'integer',
        'schema' => ['minimum' => 0],
        'nullable' => true,
        'default' => null,
        'label' => 'rules.core.partners_max_clients.label',
        'description' => 'rules.core.partners_max_clients.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 70,
    ],
    [
        'key' => 'partners.allowed_modules',
        'type' => 'json',
        // null = every module; otherwise the module keys the partner may offer.
        'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'], 'uniqueItems' => true],
        'nullable' => true,
        'default' => null,
        'label' => 'rules.core.partners_allowed_modules.label',
        'description' => 'rules.core.partners_allowed_modules.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 71,
    ],
    [
        'key' => 'partners.allowed_countries',
        'type' => 'json',
        // null = every country; otherwise ISO codes of the countries clients may be in.
        'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'], 'uniqueItems' => true],
        'nullable' => true,
        'default' => null,
        'label' => 'rules.core.partners_allowed_countries.label',
        'description' => 'rules.core.partners_allowed_countries.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 72,
    ],
    [
        'key' => 'partners.sub_resellers_allowed',
        'type' => 'boolean',
        'default' => false,
        'label' => 'rules.core.partners_sub_resellers_allowed.label',
        'description' => 'rules.core.partners_sub_resellers_allowed.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 73,
    ],
    [
        'key' => 'branding.powered_by_removable',
        'type' => 'boolean',
        'default' => false,
        'label' => 'rules.core.branding_powered_by_removable.label',
        'description' => 'rules.core.branding_powered_by_removable.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 74,
    ],
    [
        'key' => 'branding.show_powered_by',
        'type' => 'boolean',
        'default' => true,
        'label' => 'rules.core.branding_show_powered_by.label',
        'description' => 'rules.core.branding_show_powered_by.description',
        // Changed from the partner's branding page, which checks powered_by_removable.
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 75,
    ],
    [
        'key' => 'partners.suspension_grace_days',
        'type' => 'integer',
        'schema' => ['minimum' => 0, 'maximum' => 365],
        // While a partner is suspended its clients may read and export; after this, export only.
        'default' => 30,
        'label' => 'rules.core.partners_suspension_grace_days.label',
        'description' => 'rules.core.partners_suspension_grace_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 76,
    ],

    // ── Support access and data export (Phase 5B-2) ──────────────────────
    [
        'key' => 'support.max_duration_minutes',
        'type' => 'integer',
        'schema' => ['minimum' => 15, 'maximum' => 480],
        'default' => 120,
        'label' => 'rules.core.support_max_duration_minutes.label',
        'description' => 'rules.core.support_max_duration_minutes.description',
        // The partner sets a default; each client may choose its own.
        'overridable_levels' => ['platform', 'partner', 'group', 'company'],
        'category' => 'security',
        'sort_order' => 80,
    ],
    [
        'key' => 'support.auto_approve_severities',
        'type' => 'multi_enum',
        'schema' => ['items' => ['enum' => ['critical', 'high', 'normal', 'low']]],
        // Nothing is approved without a person unless the client chooses so.
        'default' => [],
        'label' => 'rules.core.support_auto_approve_severities.label',
        'description' => 'rules.core.support_auto_approve_severities.description',
        'overridable_levels' => ['platform', 'partner', 'group', 'company'],
        // Letting support in without a person needs a second person's approval.
        'sensitive' => true,
        'category' => 'security',
        'sort_order' => 81,
    ],
    [
        'key' => 'exports.retention_days',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 30],
        'default' => 7,
        'label' => 'rules.core.exports_retention_days.label',
        'description' => 'rules.core.exports_retention_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'data',
        'sort_order' => 82,
    ],
];
