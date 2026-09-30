<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; rules[] are declared in Phase 3.
*/

return [
    'key' => 'advanced_audit',
    'name' => 'advanced_audit::module.name',
    'description' => 'advanced_audit::module.description',
    'version' => '0.1.0',
    'category' => 'governance',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['advanced_audit.view', 'advanced_audit.export'],
    'rules' => [
        [
            'key' => 'advanced_audit.retention_days',
            'type' => 'integer',
            // How long the audit log keeps entries. Empty = for ever. Never under a year,
            // so a year of history is always there for a review. Country law may ask for more.
            'schema' => ['minimum' => 365, 'maximum' => 36500],
            'nullable' => true,
            'default' => null,
            'label' => 'advanced_audit::rules.retention_days.label',
            'description' => 'advanced_audit::rules.retention_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            // Removing history: a second person approves.
            'sensitive' => true,
            'country_specific' => true,
            'category' => 'audit',
            'sort_order' => 10,
        ],
        [
            'key' => 'advanced_audit.money_retention_days',
            'type' => 'integer',
            // Entries about money and pay (config audit.money_actions). Empty = for ever.
            // At least 8 years (2922 days): a placeholder for accounting law, to be checked
            // by an adviser per country.
            'schema' => ['minimum' => 2922, 'maximum' => 36500],
            'nullable' => true,
            'default' => null,
            'label' => 'advanced_audit::rules.money_retention_days.label',
            'description' => 'advanced_audit::rules.money_retention_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'sensitive' => true,
            'country_specific' => true,
            'category' => 'audit',
            'sort_order' => 20,
        ],
    ],
    'menu' => [
        [
            'key' => 'advanced_audit',
            'label' => 'advanced_audit::module.menu',
            'route' => '/audit-log?tab=report',
            'icon' => 'shield',
            'order' => 400,
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
];
