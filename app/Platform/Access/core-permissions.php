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
];
