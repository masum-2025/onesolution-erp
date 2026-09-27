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

    // ── Billing (Phase 5B-3) ─────────────────────────────────────────────
    [
        'key' => 'billing.partner_currency',
        'type' => 'string',
        // The currency wholesale invoices and payouts to a partner are in.
        'schema' => ['pattern' => '^[A-Z]{3}$'],
        'default' => 'USD',
        'label' => 'rules.core.billing_partner_currency.label',
        'description' => 'rules.core.billing_partner_currency.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'billing',
        'sort_order' => 90,
    ],
    [
        'key' => 'partners.revenue_share_bp',
        'type' => 'integer',
        // Basis points of a revenue-share invoice's subtotal (3000 = 30%). PLACEHOLDER.
        'schema' => ['minimum' => 0, 'maximum' => 10000],
        'default' => 3000,
        'label' => 'rules.core.partners_revenue_share_bp.label',
        'description' => 'rules.core.partners_revenue_share_bp.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        // What we pay out: a second person approves a change.
        'sensitive' => true,
        'category' => 'billing',
        'sort_order' => 91,
    ],
    [
        'key' => 'billing.tax_rate_bp',
        'type' => 'integer',
        // VAT/sales tax on our invoices, in basis points (1500 = 15%). Country values
        // are data; PLACEHOLDER 0 until a tax adviser confirms them.
        'schema' => ['minimum' => 0, 'maximum' => 10000],
        'default' => 0,
        'label' => 'rules.core.billing_tax_rate_bp.label',
        'description' => 'rules.core.billing_tax_rate_bp.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'sensitive' => true,
        'category' => 'billing',
        'sort_order' => 92,
    ],
    [
        'key' => 'billing.payment_terms_days',
        'type' => 'integer',
        'schema' => ['minimum' => 0, 'maximum' => 90],
        'default' => 14,
        'label' => 'rules.core.billing_payment_terms_days.label',
        'description' => 'rules.core.billing_payment_terms_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'billing',
        'sort_order' => 93,
    ],

    // ── Branded email and SMS (Phase 5B-3b) ──────────────────────────────
    [
        'key' => 'mail.custom_domain_allowed',
        'type' => 'boolean',
        // Whether a partner may send from its own domain (after DNS checks).
        'default' => true,
        'label' => 'rules.core.mail_custom_domain_allowed.label',
        'description' => 'rules.core.mail_custom_domain_allowed.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'messages',
        'sort_order' => 100,
    ],
    [
        'key' => 'notifications.sms_enabled',
        'type' => 'boolean',
        // SMS costs money per message: off until the partner turns it on.
        'default' => false,
        'label' => 'rules.core.notifications_sms_enabled.label',
        'description' => 'rules.core.notifications_sms_enabled.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'messages',
        'sort_order' => 101,
    ],
    [
        'key' => 'sms.sender_id_requires_approval',
        'type' => 'boolean',
        // Where operators register sender IDs (e.g. Bangladesh), the platform approves them first.
        'default' => true,
        'label' => 'rules.core.sms_sender_id_requires_approval.label',
        'description' => 'rules.core.sms_sender_id_requires_approval.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'messages',
        'sort_order' => 102,
    ],

    // ── Client transfer and legal documents (Phase 5B-4) ─────────────────
    [
        'key' => 'partners.transfer_code_days',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 90],
        // How long a code a partner hands a moving client stays valid.
        'default' => 14,
        'label' => 'rules.core.partners_transfer_code_days.label',
        'description' => 'rules.core.partners_transfer_code_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'partner',
        'sort_order' => 110,
    ],
    [
        'key' => 'legal.acceptance_required',
        'type' => 'boolean',
        // Account owners are asked to accept the terms and the DPA in force.
        'default' => true,
        'label' => 'rules.core.legal_acceptance_required.label',
        'description' => 'rules.core.legal_acceptance_required.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 111,
    ],

    // ── Client sub-brands and the partner API (Phase 5B-5) ───────────────
    [
        'key' => 'branding.client_sub_brands_allowed',
        'type' => 'boolean',
        // Whether the partner's clients may show their own name, logo and color to their people.
        'default' => false,
        'label' => 'rules.core.branding_client_sub_brands_allowed.label',
        'description' => 'rules.core.branding_client_sub_brands_allowed.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'partner',
        'sort_order' => 120,
    ],
    [
        'key' => 'partners.api_rate_per_minute',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 1200],
        'default' => 60,
        'label' => 'rules.core.partners_api_rate_per_minute.label',
        'description' => 'rules.core.partners_api_rate_per_minute.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'partner',
        'sort_order' => 121,
    ],
    [
        'key' => 'partners.api_key_days',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 730],
        // New keys stop working after this many days; make a new one before.
        'default' => 365,
        'label' => 'rules.core.partners_api_key_days.label',
        'description' => 'rules.core.partners_api_key_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'partner',
        'sort_order' => 122,
    ],

    // ── Self-serve sign-up and identity (Phase 5C-1) ─────────────────────
    [
        'key' => 'b2c.self_signup_allowed',
        'type' => 'boolean',
        // Whether individuals may create their own account at the partner's address.
        // Off by default; the house partner turns it on in its seed data.
        'default' => false,
        'label' => 'rules.core.b2c_self_signup_allowed.label',
        'description' => 'rules.core.b2c_self_signup_allowed.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 130,
    ],
    [
        'key' => 'b2c.default_plan',
        'type' => 'string',
        // A personal plan (plans.php, audience personal) new individuals start on.
        'schema' => ['pattern' => '^[a-z][a-z0-9_]{1,49}$'],
        'default' => 'personal_free',
        'label' => 'rules.core.b2c_default_plan.label',
        'description' => 'rules.core.b2c_default_plan.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 131,
    ],
    [
        'key' => 'identity.allowed_phone_countries',
        'type' => 'json',
        // Countries whose numbers may receive codes by SMS (guards against SMS pumping).
        'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'], 'uniqueItems' => true, 'minItems' => 1],
        'default' => ['BD'],
        'label' => 'rules.core.identity_allowed_phone_countries.label',
        'description' => 'rules.core.identity_allowed_phone_countries.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'identity',
        'sort_order' => 132,
    ],
    [
        'key' => 'identity.block_disposable_email',
        'type' => 'boolean',
        'default' => true,
        'label' => 'rules.core.identity_block_disposable_email.label',
        'description' => 'rules.core.identity_block_disposable_email.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 133,
    ],
    [
        'key' => 'identity.recovery_cooldown_hours',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 168],
        // After a password reset, the email and phone cannot be changed for this long.
        'default' => 24,
        'label' => 'rules.core.identity_recovery_cooldown_hours.label',
        'description' => 'rules.core.identity_recovery_cooldown_hours.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'identity',
        'sort_order' => 134,
    ],
    [
        'key' => 'identity.otp_per_hour_per_destination',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 20],
        'default' => 5,
        'label' => 'rules.core.identity_otp_per_hour_per_destination.label',
        'description' => 'rules.core.identity_otp_per_hour_per_destination.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'identity',
        'sort_order' => 135,
    ],
    [
        'key' => 'identity.otp_per_hour_per_ip',
        'type' => 'integer',
        'schema' => ['minimum' => 1, 'maximum' => 200],
        'default' => 20,
        'label' => 'rules.core.identity_otp_per_hour_per_ip.label',
        'description' => 'rules.core.identity_otp_per_hour_per_ip.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'identity',
        'sort_order' => 136,
    ],

    // ── Self-serve billing (Phase 5C-2) ──────────────────────────────────
    [
        'key' => 'billing.payment_gateways',
        'type' => 'multi_enum',
        // Online payment gateways offered, per country (BD: sslcommerz, seeded as data).
        'schema' => ['items' => ['enum' => ['sslcommerz']]],
        'default' => [],
        'label' => 'rules.core.billing_payment_gateways.label',
        'description' => 'rules.core.billing_payment_gateways.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'country_specific' => true,
        'category' => 'billing',
        'sort_order' => 94,
    ],
    [
        'key' => 'billing.checkout_expiry_minutes',
        'type' => 'integer',
        // An unfinished payment is given up after this long (the gateway page times out too).
        'schema' => ['minimum' => 10, 'maximum' => 1440],
        'default' => 30,
        'label' => 'rules.core.billing_checkout_expiry_minutes.label',
        'description' => 'rules.core.billing_checkout_expiry_minutes.description',
        'overridable_levels' => ['platform'],
        'category' => 'billing',
        'sort_order' => 95,
    ],
    [
        'key' => 'billing.renewal_notice_days',
        'type' => 'integer',
        // The renewal invoice is issued this many days before the paid period ends. PLACEHOLDER.
        'schema' => ['minimum' => 0, 'maximum' => 30],
        'default' => 5,
        'label' => 'rules.core.billing_renewal_notice_days.label',
        'description' => 'rules.core.billing_renewal_notice_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'billing',
        'sort_order' => 96,
    ],
    [
        'key' => 'billing.overdue_reminder_days',
        'type' => 'json',
        // Days after the due date on which a reminder goes out. PLACEHOLDER.
        'schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60], 'uniqueItems' => true, 'maxItems' => 5],
        'default' => [0, 3, 6],
        'label' => 'rules.core.billing_overdue_reminder_days.label',
        'description' => 'rules.core.billing_overdue_reminder_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'billing',
        'sort_order' => 97,
    ],
    [
        'key' => 'billing.overdue_grace_days',
        'type' => 'integer',
        // Days after the due date before an unpaid self-serve workspace becomes read-only. PLACEHOLDER.
        'schema' => ['minimum' => 0, 'maximum' => 60],
        'default' => 7,
        'label' => 'rules.core.billing_overdue_grace_days.label',
        'description' => 'rules.core.billing_overdue_grace_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'category' => 'billing',
        'sort_order' => 98,
    ],
    [
        'key' => 'b2c.trial_days',
        'type' => 'integer',
        // Free trial length of a paid personal plan; 0 = no trials. PLACEHOLDER.
        'schema' => ['minimum' => 0, 'maximum' => 90],
        'default' => 14,
        'label' => 'rules.core.b2c_trial_days.label',
        'description' => 'rules.core.b2c_trial_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 137,
    ],
    [
        'key' => 'b2c.trial_plan',
        'type' => 'string',
        // The personal plan (plans.php, audience personal) a trial gives.
        'schema' => ['pattern' => '^[a-z][a-z0-9_]{1,49}$'],
        'default' => 'personal_plus',
        'label' => 'rules.core.b2c_trial_plan.label',
        'description' => 'rules.core.b2c_trial_plan.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 138,
    ],
    [
        'key' => 'b2c.trial_reminder_days',
        'type' => 'integer',
        // A reminder goes out this many days before the trial ends.
        'schema' => ['minimum' => 0, 'maximum' => 30],
        'default' => 3,
        'label' => 'rules.core.b2c_trial_reminder_days.label',
        'description' => 'rules.core.b2c_trial_reminder_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 139,
    ],

    // ── Upgrade and privacy (Phase 5C-3) ─────────────────────────────────
    [
        'key' => 'b2c.upgrade_allowed',
        'type' => 'boolean',
        // Whether a personal workspace may become a company by itself.
        'default' => true,
        'label' => 'rules.core.b2c_upgrade_allowed.label',
        'description' => 'rules.core.b2c_upgrade_allowed.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 140,
    ],
    [
        'key' => 'b2c.upgrade_plan',
        'type' => 'string',
        // The business plan (plans.php) offered first when a personal workspace becomes a company.
        'schema' => ['pattern' => '^[a-z][a-z0-9_]{1,49}$'],
        'default' => 'starter',
        'label' => 'rules.core.b2c_upgrade_plan.label',
        'description' => 'rules.core.b2c_upgrade_plan.description',
        'overridable_levels' => ['platform', 'partner'],
        'category' => 'identity',
        'sort_order' => 141,
    ],
    [
        'key' => 'privacy.account_deletion_grace_days',
        'type' => 'integer',
        // Days between "delete my account" and the erasure; the person can cancel until then. PLACEHOLDER.
        'schema' => ['minimum' => 1, 'maximum' => 90],
        'default' => 30,
        'label' => 'rules.core.privacy_account_deletion_grace_days.label',
        'description' => 'rules.core.privacy_account_deletion_grace_days.description',
        'overridable_levels' => ['platform', 'partner'],
        'partner_editable' => false,
        'country_specific' => true,
        'category' => 'data',
        'sort_order' => 83,
    ],

    // ── Regional formats (Phase 6): country values from database/data/countries ──
    [
        'key' => 'regional.week_start',
        'type' => 'enum',
        // First day of the week in calendars and weekly reports.
        'schema' => ['enum' => ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']],
        'default' => 'mon',
        'label' => 'rules.core.regional_week_start.label',
        'description' => 'rules.core.regional_week_start.description',
        'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
        'country_specific' => true,
        'category' => 'regional',
        'sort_order' => 150,
    ],
    [
        'key' => 'regional.date_format',
        'type' => 'enum',
        // How dates are written in documents (screens follow the reader's language).
        'schema' => ['enum' => ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD.MM.YYYY', 'DD-MM-YYYY']],
        'default' => 'DD/MM/YYYY',
        'label' => 'rules.core.regional_date_format.label',
        'description' => 'rules.core.regional_date_format.description',
        'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
        'country_specific' => true,
        'category' => 'regional',
        'sort_order' => 151,
    ],
];
