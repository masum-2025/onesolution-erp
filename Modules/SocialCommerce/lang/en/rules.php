<?php

// Facebook selling rule labels.
return [
    'house_app_allowed' => [
        'label' => 'Clients may use the platform\'s Meta app',
        'description' => 'When a partner has no Meta app of its own, its clients connect through the platform\'s (Facebook then shows that app\'s name). Off: they wait for the partner\'s own app.',
    ],
    'posts_need_approval' => [
        'label' => 'Posts need approval',
        'description' => 'A post waits for someone who may approve posts before it goes out. Off: it goes out at its time once sent.',
    ],
    'max_images_per_post' => [
        'label' => 'Photos per post',
        'description' => 'Most photos one post may carry (1 to 10).',
    ],
    'max_image_mb' => [
        'label' => 'Largest photo (MB)',
        'description' => 'Biggest photo file that may be added to a post.',
    ],
    'post_footer' => [
        'label' => 'Line under every post',
        'description' => 'Put under each post in its language, e.g. "Send us a message to order".',
    ],
    'show_price' => [
        'label' => 'Show the price in product lines',
        'description' => 'A product added to a post shows its selling price.',
    ],
    'show_stock' => [
        'label' => 'Show stock in product lines',
        'description' => 'A product added to a post says whether it is in stock.',
    ],
    'min_schedule_minutes' => [
        'label' => 'Earliest schedule (minutes)',
        'description' => 'A scheduled post goes out at least this many minutes after it is sent.',
    ],
];
