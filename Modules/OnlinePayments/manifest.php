<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| A client's own payment gateway accounts (Phase 6): its customers (parents,
| patients, shop customers) pay the client directly, into its own merchant
| account. Which modules collect money, and for what, comes from their
| manifests ("payment_collectables").
*/

return [
    'key' => 'online_payments',
    'name' => 'online_payments::module.name',
    'description' => 'online_payments::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['starter', 'business', 'enterprise'],
    'permissions' => ['online_payments.view', 'online_payments.manage'],
    'rules' => [
        [
            'key' => 'online_payments.gateways',
            'type' => 'multi_enum',
            // Gateways a client may connect, per country (BD: sslcommerz, seeded as data).
            // A group may narrow the list for its companies (and lock it).
            'schema' => ['items' => ['enum' => ['sslcommerz']]],
            'default' => [],
            'label' => 'online_payments::rules.gateways.label',
            'description' => 'online_payments::rules.gateways.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'country_specific' => true,
            'category' => 'payments',
            'sort_order' => 10,
        ],
        [
            'key' => 'online_payments.live_mode_allowed',
            'type' => 'boolean',
            // Real money. Off until the platform has checked live payments end to end.
            'default' => false,
            'label' => 'online_payments::rules.live_mode_allowed.label',
            'description' => 'online_payments::rules.live_mode_allowed.description',
            'overridable_levels' => ['platform', 'partner'],
            'partner_editable' => false,
            'category' => 'payments',
            'sort_order' => 20,
        ],
        [
            'key' => 'online_payments.single_approver_wait_hours',
            'type' => 'integer',
            // With nobody else to approve a change, it takes effect after this wait
            // (owners are told at once, so a stolen login cannot redirect money quietly).
            'schema' => ['minimum' => 1, 'maximum' => 168],
            'default' => 24,
            'label' => 'online_payments::rules.single_approver_wait_hours.label',
            'description' => 'online_payments::rules.single_approver_wait_hours.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'payments',
            'sort_order' => 30,
        ],
        [
            'key' => 'online_payments.payment_expiry_minutes',
            'type' => 'integer',
            // An unfinished payment is given up after this long.
            'schema' => ['minimum' => 10, 'maximum' => 1440],
            'default' => 30,
            'label' => 'online_payments::rules.payment_expiry_minutes.label',
            'description' => 'online_payments::rules.payment_expiry_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'payments',
            'sort_order' => 40,
        ],
    ],
    'menu' => [
        [
            'key' => 'online_payments',
            'label' => 'online_payments::module.menu',
            'route' => '/online-payments',
            'icon' => 'credit-card',
            'order' => 95,
        ],
    ],
    'events' => ['payments.collected'],
    'is_core' => false,
    'requires_consent' => false,
];
