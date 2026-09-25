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
        'self_approval' => 'You cannot approve your own change. Ask another owner to approve it.',
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
    ],

];
