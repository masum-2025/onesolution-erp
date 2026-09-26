<?php

return [

    'errors' => [
        'unknown_rule' => 'This rule does not exist. Check the rule name.',
        'level_not_allowed' => '":rule" cannot be changed at :level level.',
        'invalid_value' => 'The value for ":rule" is not valid. Check the allowed format and range.',
        'invalid_bounds' => 'The limits for ":rule" are not valid. Use min, max and/or allowed.',
        'locked_by_parent' => '":rule" is locked by :by. Ask them to change it.',
        'violates_constraint' => 'The value for ":rule" is outside the limits set by :by. Choose a value inside those limits.',
        'bounds_wider_than_parent' => 'The limits for ":rule" can only be narrower than those set by :by.',
        'not_country_specific' => '":rule" is the same in every country; remove the country.',
        'module_disabled' => 'Turn on the module of ":rule" before changing it.',
        'self_approval' => 'You cannot approve your own change. Ask someone else with the approval permission.',
        'platform_only' => 'This setting is decided for your account by the platform. Contact the platform team to change it.',
        'not_pending' => 'This change is not waiting for approval any more.',
        'value_not_found' => 'That rule change was not found.',
        'nothing_to_reset' => 'This level has no own value to reset; it already uses the inherited value.',
    ],

    'core_module_name' => 'Platform',

    'levels' => [
        'platform' => 'platform',
        'partner' => 'partner',
        'plan' => 'plan',
        'group' => 'group',
        'company' => 'company',
        'branch' => 'branch',
        'department' => 'department',
        'role' => 'role',
        'user' => 'user',
    ],

    'messages' => [
        'pending_approval' => 'Change saved. It takes effect after another owner approves it.',
        'saved' => 'Change saved.',
        'reset' => 'This level now uses the inherited value.',
    ],

    'core' => [
        'tenancy_max_depth' => [
            'label' => 'Maximum organization levels',
            'description' => 'How many levels deep an organization tree can go.',
        ],
        'tenancy_allowed_parents' => [
            'label' => 'Allowed organization structure',
            'description' => 'Which type of unit can be placed under which.',
        ],
        'tenancy_token_ttl_minutes' => [
            'label' => 'Sign-in session length (minutes)',
            'description' => 'How long a sign-in stays valid before signing in again.',
        ],
        'modules_purge_delay_days' => [
            'label' => 'Waiting period before deleting module data (days)',
            'description' => 'Days between confirming a data deletion and the deletion itself.',
        ],
        'access_separation_of_duties' => [
            'label' => 'Separation of duties',
            'description' => 'Pairs of permissions one person may not hold together, such as running and approving payroll. Changes need a second person\'s approval.',
        ],
        'plans_max_users' => [
            'label' => 'Staff users included',
            'description' => 'How many owners and staff the whole subscription may have. Portal users do not count. Empty means unlimited.',
        ],
        'plans_max_branches' => [
            'label' => 'Branches included',
            'description' => 'How many branches the whole subscription may have. Empty means unlimited.',
        ],
        'plans_max_storage_mb' => [
            'label' => 'Storage included (MB)',
            'description' => 'File storage for the whole subscription. Empty means unlimited.',
        ],
        'partners_max_clients' => [
            'label' => 'Maximum clients',
            'description' => 'How many client accounts the partner may have. Empty means unlimited.',
        ],
        'partners_allowed_modules' => [
            'label' => 'Modules the partner may offer',
            'description' => 'Module keys the partner may sell to its clients. Empty means every module.',
        ],
        'partners_allowed_countries' => [
            'label' => 'Countries the partner may serve',
            'description' => 'Country codes where the partner\'s clients may be. Empty means every country.',
        ],
        'partners_sub_resellers_allowed' => [
            'label' => 'Sub-resellers allowed',
            'description' => 'Whether the partner may have resellers of its own.',
        ],
        'branding_powered_by_removable' => [
            'label' => '"Powered by" may be hidden',
            'description' => 'Whether the partner may hide the "Powered by" badge.',
        ],
        'branding_show_powered_by' => [
            'label' => 'Show "Powered by"',
            'description' => 'Shows the platform\'s "Powered by" badge in the partner\'s app.',
        ],
        'partners_suspension_grace_days' => [
            'label' => 'Grace period after suspension (days)',
            'description' => 'While a partner is suspended, its clients can still read and export their data for this many days; after that, export only.',
        ],
        'support_max_duration_minutes' => [
            'label' => 'Longest support access (minutes)',
            'description' => 'How long an approved support access may last before it ends by itself.',
        ],
        'support_auto_approve_severities' => [
            'label' => 'Support requests approved without a person',
            'description' => 'Support requests of these urgency levels are let in at once. Empty means someone always approves.',
            'options' => ['critical' => 'Critical', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'],
        ],
        'exports_retention_days' => [
            'label' => 'Keep data exports for (days)',
            'description' => 'How long a finished data export stays available to download.',
        ],
        'billing_partner_currency' => [
            'label' => 'Partner billing currency',
            'description' => 'The currency wholesale invoices and commission payouts to this partner are in (ISO code, e.g. USD).',
        ],
        'partners_revenue_share_bp' => [
            'label' => 'Partner revenue share (basis points)',
            'description' => 'The partner\'s share of each revenue-share invoice, before tax. 3000 = 30%.',
        ],
        'billing_tax_rate_bp' => [
            'label' => 'Tax on invoices (basis points)',
            'description' => 'VAT or sales tax added to invoices. 1500 = 15%. Set per country.',
        ],
        'billing_payment_terms_days' => [
            'label' => 'Payment terms (days)',
            'description' => 'How many days after issue an invoice is due.',
        ],
        'mail_custom_domain_allowed' => [
            'label' => 'Own sending domain allowed',
            'description' => 'Whether the partner may send email from its own domain once its DNS records check out.',
        ],
        'notifications_sms_enabled' => [
            'label' => 'Send SMS',
            'description' => 'Send text messages as well as email. Each message costs money.',
        ],
        'sms_sender_id_requires_approval' => [
            'label' => 'SMS sender ID needs approval',
            'description' => 'Where operators register sender IDs, the platform approves a partner\'s sender ID before it is used.',
        ],
        'partners_transfer_code_days' => [
            'label' => 'Transfer codes are valid for (days)',
            'description' => 'How long a code you give a client that wants to move to you stays valid.',
        ],
        'legal_acceptance_required' => [
            'label' => 'Clients accept terms and the DPA',
            'description' => 'Account owners are asked to accept the terms of service and the data processing agreement in force.',
        ],
        'branding_client_sub_brands_allowed' => [
            'label' => 'Clients may use their own brand',
            'description' => 'Clients can show their own name, logo and color to their own people (app, their own address, emails).',
        ],
        'partners_api_rate_per_minute' => [
            'label' => 'API requests per minute per key',
            'description' => 'How many requests one API key may make each minute.',
        ],
        'partners_api_key_days' => [
            'label' => 'API keys are valid for (days)',
            'description' => 'A new API key stops working after this many days.',
        ],
        'b2c_self_signup_allowed' => [
            'label' => 'Individuals can sign up by themselves',
            'description' => 'Anyone can create their own account and personal workspace at your address, with an email or phone code.',
        ],
        'b2c_default_plan' => [
            'label' => 'Plan for new individuals',
            'description' => 'The personal plan a self-signed-up person starts on.',
        ],
        'identity_allowed_phone_countries' => [
            'label' => 'Countries that get SMS codes',
            'description' => 'Phone numbers from other countries cannot sign up by SMS. Keeps SMS costs and abuse down.',
        ],
        'identity_block_disposable_email' => [
            'label' => 'Refuse throwaway email addresses',
            'description' => 'Sign-up with a temporary inbox (e.g. mailinator) is refused.',
        ],
        'identity_recovery_cooldown_hours' => [
            'label' => 'Wait after a password reset (hours)',
            'description' => 'After someone resets their password, their email and phone cannot be changed for this long.',
        ],
        'identity_otp_per_hour_per_destination' => [
            'label' => 'Codes per hour to one address',
            'description' => 'How many one-time codes one email or phone number can get in an hour.',
        ],
        'identity_otp_per_hour_per_ip' => [
            'label' => 'Codes per hour from one network',
            'description' => 'How many one-time codes can be asked for from one IP address in an hour.',
        ],
    ],

    'categories' => [
        'organization' => 'Organization',
        'security' => 'Security',
        'data' => 'Data',
        'access' => 'Access',
        'plan' => 'Plan limits',
        'partner' => 'Partner account',
        'billing' => 'Billing',
        'messages' => 'Email and SMS',
        'identity' => 'Sign-up and sign-in',
    ],
];
