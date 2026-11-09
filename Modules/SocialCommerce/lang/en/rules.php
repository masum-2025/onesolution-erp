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
    'auto_reply' => [
        'label' => 'Automatic replies',
        'description' => 'The bot answers comments and messages it understands (each page also has its own switch).',
    ],
    'price_public' => [
        'label' => 'Price in public comment replies (new pages)',
        'description' => 'On: a price goes under the comment. Off: it goes to the customer\'s inbox with a short public note. Each page has its own switch; this is where a new page starts.',
    ],
    'reply_cooldown_minutes' => [
        'label' => 'Do not answer the same question again within (minutes)',
        'description' => 'Stops the bot answering a customer who asks the same thing twice in a row.',
    ],
    'handoff_after_unknown' => [
        'label' => 'Ask a person after this many questions not understood',
        'description' => 'The customer is told a person will answer, and the conversation waits in the inbox.',
    ],
    'ai_understanding' => [
        'label' => 'AI reads what the keywords miss',
        'description' => 'When no keyword matches, the AI assistant reads the message to tell what the customer asks. Needs the AI assistant module; each reading counts against its monthly limit.',
    ],
    'ai_fill_order' => [
        'label' => 'AI fills order details from one message',
        'description' => 'When a customer writes name, phone and address together in Messenger, those questions are filled at once.',
    ],
    'keep_messages_days' => [
        'label' => 'Keep conversations (days)',
        'description' => 'Conversations with no news for this long are forgotten, with the customers\' names.',
    ],
    'messenger_orders' => [
        'label' => 'Take orders in Messenger',
        'description' => 'The bot asks the product, how many, name, mobile, address and area, then makes a draft order in Online orders. A page also needs its branch and warehouse set.',
    ],
    'draft_hours' => [
        'label' => 'Drop an unfinished order after (hours)',
        'description' => 'An order the customer stops answering is dropped after this long.',
    ],
    'max_quantity' => [
        'label' => 'Most pieces per Messenger order',
        'description' => 'Bigger orders are taken by a person.',
    ],
    'notify_order_status' => [
        'label' => 'Tell the customer about their order',
        'description' => 'In Messenger, when it is confirmed, sent, delivered or cancelled.',
    ],
    'min_schedule_minutes' => [
        'label' => 'Earliest schedule (minutes)',
        'description' => 'A scheduled post goes out at least this many minutes after it is sent.',
    ],
];
