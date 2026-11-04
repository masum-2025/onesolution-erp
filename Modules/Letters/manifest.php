<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('letters.*')).
*/

$rule = fn (string $key, string $type, mixed $default, array $levels, int $order, array $extra = []) => [
    'key' => "letters.{$key}",
    'type' => $type,
    'default' => $default,
    'label' => "letters::rules.{$key}.label",
    'description' => "letters::rules.{$key}.description",
    'overridable_levels' => $levels,
    'category' => 'letters',
    'sort_order' => $order,
    ...$extra,
];
$all = ['platform', 'partner', 'plan', 'group', 'company'];

return [
    'key' => 'letters',
    'name' => 'letters::module.name',
    'description' => 'letters::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Letters are for HRM's employees (EmployeeDirectory); Payroll adds pay fields when it is on.
    'requires' => ['hrm'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // See the letters issued to the people of a unit and below (not salary letters' content).
        'letters.view',
        // Make and change the company's letter designs and their images.
        'letters.design',
        // Issue letters to the people of a unit and below, and revoke them.
        'letters.issue',
        // Put salary in letter designs, issue and open salary letters (where the company allows salary in letters).
        'letters.salary',
    ],
    'rules' => [
        // Whether letters may show pay at all (basic, gross, breakdown); off hides every salary field.
        $rule('salary_fields_enabled', 'boolean', true, $all, 10, ['sensitive' => true]),
        // Letter numbers: {CODE} the design's code, {YYYY} or {YY} the year, {SEQ:n} the number of n digits (per code and year).
        $rule('number_format', 'string', '{CODE}/{YYYY}/{SEQ:4}', $all, 20,
            ['schema' => ['pattern' => '^(?=.*\\{SEQ(:[3-8])?\\})[A-Za-z0-9{}:\\-\\/ ]{4,40}$']]),
        // Employees see the letters shared with them (in the app and the client's portal), and are told by mail.
        $rule('employees_see_own', 'boolean', true, $all, 30),
    ],
    'menu' => [
        [
            'key' => 'letters',
            'label' => 'letters::module.menu',
            'route' => '/letters',
            'icon' => 'mail',
            'order' => 32,
            'section' => 'people',
            'children' => [
                ['key' => 'issued', 'label' => 'letters::module.menu_issued', 'route' => '/letters', 'permission' => 'letters.view'],
                ['key' => 'templates', 'label' => 'letters::module.menu_templates', 'route' => '/letters/templates', 'permission' => 'letters.design'],
                ['key' => 'mine', 'label' => 'letters::module.menu_mine', 'route' => '/letters/me'],
            ],
        ],
    ],
    'quick_actions' => [],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => [
        ['key' => 'templates', 'label' => 'letters::module.menu_templates', 'route' => '/letters/templates', 'permission' => 'letters.design'],
    ]],
    'notifications' => [
        // To the employee a letter is shared with (its name only: the letter is opened in the app or the portal).
        'letters.issued' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'title', 'link'],
            'audience' => 'client',
            'path' => '/letters/me',
        ],
    ],
    // An employee opens their own letters from their record in the client's portal.
    'portal_pages' => [
        ['subject' => 'hrm.employee', 'label' => 'letters::module.portal_letters', 'route' => '/portal/letters'],
    ],
];
