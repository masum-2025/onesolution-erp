<?php

return [
    'statuses' => [
        'probation' => 'On probation',
        'active' => 'Active',
        'on_notice' => 'On notice',
        'exited' => 'Left',
    ],
    'events' => [
        'hired' => 'Hired',
        'confirmed' => 'Confirmed after probation',
        'transferred' => 'Transferred',
        'promoted' => 'Promoted',
        'notice_given' => 'Notice given',
        'exited' => 'Left the job',
        'rehired' => 'Rehired',
    ],
    'genders' => [
        'female' => 'Female',
        'male' => 'Male',
        'other' => 'Other',
        'undisclosed' => 'Prefer not to say',
    ],

    'errors' => [
        'not_found' => 'Employee not found. Check the link or search the employee list.',
        'unknown_step' => 'This employment step does not exist. Use confirm, transfer, promote, notice, exit or rehire.',
        'position_not_found' => 'Position not found. Choose a position from the list.',
        'document_not_found' => 'Document not found. It may have been removed.',
        'version_conflict' => 'Someone changed this employee a moment ago. Reload to see the latest details, then make your change again.',
        'not_company_unit' => 'Employees work in a company or one of its branches or departments. Choose one of those.',
        'other_company' => 'This unit belongs to another company. An employee can only move inside their own company.',
        'not_employed' => 'This person has left. Rehire them first.',
        'not_on_probation' => 'This employee is not on probation.',
        'already_employed' => 'This person is still employed.',
        'same_unit' => 'The employee already works in this unit.',
        'same_position' => 'The employee already has this position.',
        'position_in_use' => 'People hold this position. Mark it inactive instead of removing it.',
    ],

    'validation' => [
        'required_by_rule' => 'This detail is required for employees here.',
        'employment_type' => 'Choose one of the kinds of employment offered here.',
        'document_type' => 'Choose one of the kinds of documents offered here.',
        'duplicate_national_id' => 'Another employee of this company has the same national id.',
        'manager' => 'The manager must be a current employee of the same company, not the person themselves.',
        'position_inactive' => 'This position is no longer used. Choose another one.',
        'document_size' => 'The file is larger than :max KB. Choose a smaller file.',
        'exit_before_joining' => 'The last day cannot be before the joining date.',
    ],

    // What an employee sees of their own record in the portal.
    'portal' => [
        'subject' => 'Employee',
        'full_name' => 'Name',
        'employee_code' => 'Employee code',
        'position' => 'Position',
        'unit' => 'Works in',
        'joined_on' => 'Joined on',
        'status' => 'Status',
        'phone' => 'Phone',
        'email' => 'Email',
    ],
];
