<?php

return [
    'stages' => [
        'label' => 'Steps of an application',
        'description' => 'The steps an application goes through, in order. New applications start at the first.',
    ],
    'public_apply' => [
        'label' => 'Applications from the careers page',
        'description' => 'Whether people may apply on the public careers page.',
    ],
    'cv_max_kb' => [
        'label' => 'Largest CV (KB)',
        'description' => 'The largest PDF or Word file taken as a CV.',
    ],
    'applications_per_email_day' => [
        'label' => 'Applications per email a day',
        'description' => 'How many times one email address may apply in a day.',
    ],
    'keep_rejected_days' => [
        'label' => 'Keep people not taken (days)',
        'description' => 'Rejected or withdrawn applicants are forgotten (details and CV removed) after this many days.',
    ],
    'categories' => [
        'recruitment' => 'Recruitment',
    ],
];
