<?php

// Projects (PRJ-1): statuses and errors that say what to do next.

return [
    'statuses' => [
        'planned' => 'Planned',
        'active' => 'Active',
        'on_hold' => 'On hold',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],
    'no_due' => 'no due day',
    'posting_keys' => [
        'services' => 'Project services income',
    ],
    'errors' => [
        'not_workplace' => 'Projects run at a company or a branch that is open. Choose one of them.',
        'project_not_found' => 'This project was not found here. Open it again from the projects list.',
        'task_not_found' => 'This task was not found in the project. Reload the project.',
        'template_not_found' => 'This template was not found here. Choose one from the list.',
        'not_template_owner' => 'Templates are kept by a group or a company. This one is kept above: change it there.',
        'version_conflict' => 'Someone changed this meanwhile. Reload and try again.',
        'wrong_step' => 'The project is :status now, so this cannot be done. Reload to see it as it is.',
        'project_closed' => 'This project is done or cancelled: reopen it to change its tasks.',
        'dates_order' => 'The due day cannot be before the start day.',
        'client_needed' => 'Choose the client, or type the client\'s name.',
        'client_unknown' => 'This client was not found among your clients. Choose one from the list.',
        'not_assignable' => 'Choose only people who work on projects here (and the lead among the task\'s people).',
        'too_many_assignees' => 'A task can be for at most :max people.',
        'too_many_tasks' => 'A project can have at most :max tasks. Split the work into another project.',
        'crm_off' => 'Quotes are made in CRM, which is off here. Turn CRM on to quote clients.',
        'books_off' => 'Invoices are made in Accounting, which is off here or has no books yet. Set up the books first.',
        'client_not_in_crm' => 'This project\'s client is only a name. Add the client to CRM (button on the project) to quote or invoice them.',
        'not_your_task' => 'You can change only the tasks you are on. Ask the project\'s manager.',
    ],
];
