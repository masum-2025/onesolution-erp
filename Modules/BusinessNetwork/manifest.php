<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business connections (NET-1): a service provider (a company) and its client
| (a group or a company), both clients of the platform, connect by an
| invitation code; the client chooses which companies and branches
| (factories) the provider may deal with. Other modules exchange work over
| it as messages ("connection_kinds"); the logic is app/Platform/Connections.
*/

return [
    'key' => 'business_network',
    'name' => 'business_network::module.name',
    'description' => 'business_network::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    'permissions' => ['business_network.view', 'business_network.manage'],
    'rules' => [
        [
            'key' => 'business_network.invite_valid_days',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 60],
            'default' => 14,
            'label' => 'business_network::rules.invite_valid_days.label',
            'description' => 'business_network::rules.invite_valid_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'network',
            'sort_order' => 10,
        ],
        [
            'key' => 'business_network.max_file_mb',
            'type' => 'integer',
            // Reports and certificates sent across a connection; storage is the sender's and the receiver's.
            'schema' => ['minimum' => 1, 'maximum' => 100],
            'default' => 20,
            'label' => 'business_network::rules.max_file_mb.label',
            'description' => 'business_network::rules.max_file_mb.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'network',
            'sort_order' => 20,
        ],
        [
            'key' => 'business_network.allow_other_partners',
            'type' => 'boolean',
            // Connecting with a client of another provider of the platform (white-label partner):
            // both sides' value must allow it. Neither partner ever sees the other's client.
            'default' => true,
            'label' => 'business_network::rules.allow_other_partners.label',
            'description' => 'business_network::rules.allow_other_partners.description',
            'overridable_levels' => ['platform', 'partner'],
            'category' => 'network',
            'sort_order' => 30,
        ],
    ],
    'menu' => [
        [
            'key' => 'business_network',
            'label' => 'business_network::module.menu',
            'route' => '/connections',
            'icon' => 'network',
            'order' => 85,
            'permission' => 'business_network.view',
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => [
        ['key' => 'connections', 'label' => 'business_network::module.menu', 'route' => '/connections', 'permission' => 'business_network.view'],
    ]],
    'notifications' => [
        'business_network.connected' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'link'],
            'audience' => 'client',
            'path' => '/connections',
        ],
        'business_network.paused' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'link'],
            'audience' => 'client',
            'path' => '/connections',
        ],
        'business_network.ended' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'reason', 'link'],
            'audience' => 'client',
            'path' => '/connections',
        ],
        'business_network.message_failed' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'other', 'reason', 'link'],
            'audience' => 'client',
            'path' => '/connections',
        ],
    ],
];
