<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'offline_mode',
    'name' => 'offline_mode::module.name',
    'description' => 'offline_mode::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['*'],
    // use: work offline on a device; manage: see and revoke devices, decide on held operations.
    'permissions' => ['offline_mode.use', 'offline_mode.manage'],
    'rules' => [
        [
            'key' => 'offline_mode.offline_lease_hours',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 720],
            'default' => 24,
            'label' => 'offline_mode::rules.offline_lease_hours.label',
            'description' => 'offline_mode::rules.offline_lease_hours.description',
            // Per role (Phase 4) and per user as well.
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch', 'role', 'user'],
            'category' => 'lease',
            'sort_order' => 10,
        ],
        [
            'key' => 'offline_mode.max_cached_records',
            'type' => 'integer',
            'schema' => ['minimum' => 100, 'maximum' => 1000000],
            'default' => 5000,
            'label' => 'offline_mode::rules.max_cached_records.label',
            'description' => 'offline_mode::rules.max_cached_records.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'category' => 'storage',
            'sort_order' => 20,
        ],
        [
            'key' => 'offline_mode.allow_offline_payments',
            'type' => 'boolean',
            'default' => false,
            'label' => 'offline_mode::rules.allow_offline_payments.label',
            'description' => 'offline_mode::rules.allow_offline_payments.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'sensitive' => true,
            'category' => 'payments',
            'sort_order' => 30,
        ],
        // Phase 7: secure sync.
        [
            'key' => 'offline_mode.sync_batch_max',
            'type' => 'integer',
            // Operations one sync request may carry; a device sends the rest in the next one.
            'schema' => ['minimum' => 1, 'maximum' => 1000],
            'default' => 200,
            'label' => 'offline_mode::rules.sync_batch_max.label',
            'description' => 'offline_mode::rules.sync_batch_max.description',
            'overridable_levels' => ['platform', 'partner'],
            'category' => 'sync',
            'sort_order' => 40,
        ],
        [
            'key' => 'offline_mode.quarantine_days',
            'type' => 'integer',
            // How long held operations wait for a decision before they are discarded. PLACEHOLDER.
            'schema' => ['minimum' => 1, 'maximum' => 365],
            'default' => 30,
            'label' => 'offline_mode::rules.quarantine_days.label',
            'description' => 'offline_mode::rules.quarantine_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'sync',
            'sort_order' => 50,
        ],
    ],
    'menu' => [
        [
            'key' => 'offline_mode',
            'label' => 'offline_mode::module.menu',
            'route' => '/offline',
            'icon' => 'wifi-off',
            'order' => 95,
        ],
    ],
    'events' => ['offline.device_revoked', 'offline.operation_quarantined'],
    'is_core' => false,
    'requires_consent' => false,
    // Every module has a dashboard and a settings page; widgets and own setting
    // screens come with the module's business screens (its rules show already).
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => [
        ['key' => 'devices', 'label' => 'offline_mode::module.menu', 'route' => '/offline', 'permission' => 'offline_mode.manage'],
    ]],
];
