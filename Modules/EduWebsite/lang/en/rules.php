<?php

return [
    'templates' => [
        'label' => 'Website templates offered',
        'description' => 'Templates a school may choose for its website. Turn some off for everyone below.',
        'options' => ['school' => 'School (colourful)', 'minimal' => 'Minimal', 'premium' => 'Premium (ink and gold)'],
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
    'categories' => [
        'website' => 'Website',
    ],
];
