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
    'permissions' => ['offline_mode.manage'],
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
    ],
    'menu' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
