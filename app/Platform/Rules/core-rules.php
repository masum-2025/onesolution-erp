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
];
