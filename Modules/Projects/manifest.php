<?php

use Modules\Projects\Connections\RenewalCancelledHandler;
use Modules\Projects\Connections\RenewalRequestHandler;
use Modules\Projects\Dashboard\MyTasks;
use Modules\Projects\Dashboard\ProjectsAttention;
use Modules\Projects\Dashboard\ProjectsByStatus;

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Projects (PRJ-1): a company's or branch's projects for its clients (CRM
| contacts when CRM is on, else a name), with tasks for one or more people,
| templates of the usual steps and everyone's own task list. Works on its
| own: no business connection is needed. Numbers here are rules.
*/

$rule = fn (string $key, string $type, mixed $default, array $extra = []) => [
    'key' => "projects.{$key}",
    'type' => $type,
    'default' => $default,
    'label' => "projects::rules.{$key}.label",
    'description' => "projects::rules.{$key}.description",
    'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company'],
    'category' => 'projects',
    ...$extra,
];

return [
    'key' => 'projects',
    'name' => 'projects::module.name',
    'description' => 'projects::module.description',
    'version' => '0.1.0',
    'category' => 'operations',
    'requires' => [],
    'sectors' => ['*'],
    'plans' => ['business', 'enterprise'],
    // work: change the status of one's own tasks and add notes; manage: projects and all their tasks;
    // bill: quotes (CRM) and invoices (Accounting) for a project (PRJ-2).
    'permissions' => ['projects.view', 'projects.manage', 'projects.work', 'projects.configure', 'projects.bill'],
    // Invoices made from a project are income on the account mapped to this key.
    'ledger_accounts' => [
        'projects.services' => ['label' => 'projects::projects.posting_keys.services', 'type' => 'income'],
    ],
    'rules' => [
        // Project numbers: PREFIX-YEAR-0001, per company.
        $rule('number_prefix', 'string', 'PRJ', ['schema' => ['minLength' => 1, 'maxLength' => 8, 'pattern' => '^[A-Z0-9]{1,8}$'], 'sort_order' => 10]),
        $rule('max_tasks', 'integer', 300, ['schema' => ['minimum' => 10, 'maximum' => 2000], 'sort_order' => 20]),
        $rule('max_assignees', 'integer', 10, ['schema' => ['minimum' => 1, 'maximum' => 50], 'sort_order' => 30]),
        // The people of a task are told once when it passes its due day.
        $rule('overdue_alert', 'boolean', true, ['sort_order' => 40]),
    ],
    'menu' => [
        [
            'key' => 'projects',
            'label' => 'projects::module.menu',
            'route' => '/projects',
            'icon' => 'kanban',
            'order' => 45,
            'section' => 'business',
            'permission' => 'projects.view',
            'children' => [
                ['key' => 'list', 'label' => 'projects::module.menu_projects', 'route' => '/projects', 'permission' => 'projects.view'],
                ['key' => 'mine', 'label' => 'projects::module.menu_mine', 'route' => '/projects/my-tasks', 'permission' => 'projects.work'],
            ],
        ],
    ],
    'events' => [],
    // Optional, over a business connection: a connected client's Compliance asks for renewals here.
    'connection_kinds' => [RenewalRequestHandler::class, RenewalCancelledHandler::class],
    'is_core' => false,
    'requires_consent' => false,
    'attention' => [ProjectsAttention::class],
    'dashboard' => ['widgets' => [
        ['key' => 'by_status', 'label' => 'projects::dashboard.by_status', 'type' => 'bars', 'provider' => ProjectsByStatus::class, 'permission' => 'projects.view', 'overview' => true],
        ['key' => 'my_tasks', 'label' => 'projects::dashboard.my_tasks', 'type' => 'list', 'provider' => MyTasks::class, 'permission' => 'projects.work', 'overview' => true],
    ]],
    'settings' => ['pages' => [
        ['key' => 'templates', 'label' => 'projects::module.menu_templates', 'route' => '/projects/templates', 'permission' => 'projects.configure'],
    ]],
    'notifications' => [
        'projects.task_assigned' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'project', 'task', 'due', 'by', 'link'],
            'audience' => 'client',
            'path' => '/projects/my-tasks',
        ],
        'projects.task_overdue' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'project', 'task', 'due', 'link'],
            'audience' => 'client',
            'path' => '/projects/my-tasks',
        ],
    ],
];
