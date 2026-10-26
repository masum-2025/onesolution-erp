<?php

return [
    'templates' => [
        'label' => 'Website templates offered',
        'description' => 'Templates a school may choose for its website. Turn some off for everyone below.',
        'options' => ['school' => 'School (colourful)', 'minimal' => 'Minimal', 'premium' => 'Premium (ink and gold)', 'campus' => 'Campus (college, university)', 'madrasa' => 'Madrasa (green and gold)'],
    ],
    'default_template' => [
        'label' => 'Template a new website starts with',
        'description' => 'Chosen first when a school starts its website (per kind of institution); any offered template can be picked.',
        'options' => ['school' => 'School (colourful)', 'minimal' => 'Minimal', 'premium' => 'Premium (ink and gold)', 'campus' => 'Campus (college, university)', 'madrasa' => 'Madrasa (green and gold)'],
    ],
    'starter_blocks' => [
        'label' => 'Blocks of a new home page',
        'description' => 'The blocks the home page of a new website starts with, in this order.',
    ],
    'max_pages' => [
        'label' => 'Website pages at most',
        'description' => 'How many pages a website may have (set per plan).',
    ],
    'max_media_mb' => [
        'label' => 'Largest website file (MB)',
        'description' => 'The largest picture or PDF that can be put on a website.',
    ],
    'own_domain' => [
        'label' => 'Website on its own domain',
        'description' => 'A school may put its website on a domain it owns (proven by a DNS record).',
    ],
    'enquiry_retention_days' => [
        'label' => 'Keep admission enquiries (days)',
        'description' => 'Enquiries from the website, with the names and numbers in them, are removed after this many days.',
    ],
    'enquiry_per_phone_day' => [
        'label' => 'Enquiries per mobile number a day',
        'description' => 'How many enquiries one number may send in a day (against spam).',
    ],
    'enquiry_notify_email' => [
        'label' => 'Mail about new enquiries',
        'description' => 'The people who handle enquiries get a mail when one comes (the class only, no names or numbers).',
    ],
    'categories' => [
        'website' => 'Website',
    ],
];
