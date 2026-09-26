<?php

return [

    'unlimited' => 'unlimited',

    'errors' => [
        'users_limit_reached' => 'Your :plan plan includes :max staff users, and all of them are in use.:upgrade Or suspend someone who no longer needs access.',
        'branches_limit_reached' => 'Your :plan plan includes :max branches, and all of them are in use.:upgrade Or archive a branch you no longer use.',
        'storage_mb_limit_reached' => 'Your :plan plan includes :max MB of storage, and it is full.:upgrade Or delete files you no longer need.',
        'upgrade_to' => 'The :plan plan includes :max.',
        'unknown_plan' => 'This plan does not exist. Choose one from the list.',
        'same_plan' => 'This client is already on that plan.',
        'not_top_level' => 'A plan belongs to the whole subscription. Change it at the top organization.',
        'no_package' => 'This organization has no sector package. Set its sector first.',
        'no_price' => 'This plan has no price in :currency per :period. Add one to the plan, or choose another currency or period.',
        'plan_in_use' => 'Clients are on this plan, so its base plan and modules are fixed. Make a new plan instead.',
        'module_not_in_base' => 'The module ":module" is not in the base plan, or you do not offer it.',
        'module_needs' => '":module" needs ":required"; include both.',
    ],

    'periods' => [
        'monthly' => 'month',
        'yearly' => 'year',
    ],

    'messages' => [
        'applied' => 'The sector package was applied.',
        'already_applied' => 'This sector package was applied before; nothing changed.',
        'plan_changed' => 'The plan is now :plan.',
    ],

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'description' => 'The essentials for a small organization: people, attendance, payroll, stock and accounts.',
        ],
        'business' => [
            'name' => 'Business',
            'description' => 'Every module, more users and branches, reports and AI tools.',
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Every module with no fixed limits, for groups of companies.',
        ],
    ],

    'sectors' => [
        'general' => [
            'name' => 'General business',
            'description' => 'Any kind of organization: people, attendance and accounts.',
        ],
        'school' => [
            'name' => 'School',
            'description' => 'Staff, attendance, payroll and accounts, with principal, teacher and office roles.',
        ],
        'factory' => [
            'name' => 'Factory',
            'description' => 'Workers, shifts, payroll, stock and production.',
        ],
        'retail' => [
            'name' => 'Retail shop',
            'description' => 'Stock, customers and accounts for shops and stores.',
        ],
    ],

];
