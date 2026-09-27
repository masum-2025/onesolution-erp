<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| B2B2C portals (Phase 5C-4): a client's own people (parents, employees,
| customers) see only the records linked to them. Record kinds come from
| other modules' manifests ("portal_subjects").
*/

return [
    'key' => 'client_portal',
    'name' => 'client_portal::module.name',
    'description' => 'client_portal::module.description',
    'version' => '0.1.0',
    'category' => 'platform',
    'requires' => [],
    'sectors' => ['*'],
    // Business plans; a partner may sell it as an add-on through its own plans.
    'plans' => ['starter', 'business', 'enterprise'],
    'permissions' => ['client_portal.view', 'client_portal.manage'],
    'rules' => [
        [
            'key' => 'client_portal.link_approval',
            'type' => 'enum',
            // manual: the client approves every link. auto_verified: a link made by an account
            // that verified the invited email or phone is active at once.
            'schema' => ['enum' => ['manual', 'auto_verified']],
            'default' => 'manual',
            'label' => 'client_portal::rules.link_approval.label',
            'description' => 'client_portal::rules.link_approval.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'category' => 'portal',
            'sort_order' => 10,
        ],
        [
            'key' => 'client_portal.invitation_valid_days',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 60],
            'default' => 14,
            'label' => 'client_portal::rules.invitation_valid_days.label',
            'description' => 'client_portal::rules.invitation_valid_days.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'category' => 'portal',
            'sort_order' => 20,
        ],
        [
            'key' => 'client_portal.max_links_per_person',
            'type' => 'integer',
            // e.g. a parent with several children at one school.
            'schema' => ['minimum' => 1, 'maximum' => 50],
            'default' => 10,
            'label' => 'client_portal::rules.max_links_per_person.label',
            'description' => 'client_portal::rules.max_links_per_person.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
            'category' => 'portal',
            'sort_order' => 30,
        ],
        [
            'key' => 'client_portal.hidden_fields',
            'type' => 'json',
            // Fields portal members never see, as "kind.field": ["school.student.date_of_birth"].
            'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$'], 'uniqueItems' => true, 'maxItems' => 200],
            'default' => [],
            'label' => 'client_portal::rules.hidden_fields.label',
            'description' => 'client_portal::rules.hidden_fields.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'portal',
            'sort_order' => 40,
        ],
        [
            'key' => 'client_portal.allow_online_payment',
            'type' => 'boolean',
            // Portal members may pay online (fees, invoices) once a module offers it.
            'default' => false,
            'label' => 'client_portal::rules.allow_online_payment.label',
            'description' => 'client_portal::rules.allow_online_payment.description',
            'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch'],
            'category' => 'portal',
            'sort_order' => 50,
        ],
    ],
    'menu' => [
        [
            'key' => 'client_portal',
            'label' => 'client_portal::module.menu',
            'route' => '/portal-admin',
            'icon' => 'door-open',
            'order' => 90,
        ],
    ],
    'events' => ['portal.link_approved', 'portal.link_revoked'],
    'is_core' => false,
    'requires_consent' => false,
];
