<?php

/*
|--------------------------------------------------------------------------
| Platform (core) permissions
|--------------------------------------------------------------------------
|
| Module permissions come from each module manifest; "rules.edit.{module}"
| is added for every module that declares rules. Labels:
| lang/{locale}/access.php "permissions.{key with dots as underscores}".
|
*/

return [
    ['key' => 'organizations.manage', 'group' => 'organization'],
    ['key' => 'organizations.move', 'group' => 'organization'],
    ['key' => 'members.manage', 'group' => 'people'],
    ['key' => 'roles.manage', 'group' => 'people'],
    ['key' => 'modules.manage', 'group' => 'setup'],
    ['key' => 'rules.approve', 'group' => 'setup'],
    // Phase 5B-2: trust and data ownership.
    ['key' => 'support.approve', 'group' => 'security'],
    ['key' => 'audit.view', 'group' => 'security'],
    ['key' => 'data.export', 'group' => 'security'],
    // Phase 5B-3: the client's own plan and invoices.
    ['key' => 'billing.view', 'group' => 'billing'],
    // Phase 5B-5: the client's own brand (where its partner allows it).
    ['key' => 'branding.manage', 'group' => 'organization'],
];
