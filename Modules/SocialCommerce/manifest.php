<?php

use Modules\SocialCommerce\Dashboard\InboxAttention;

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules (Rules::get('social_commerce.*')).
*/

$rule = fn (string $key, string $type, mixed $default, array $levels, int $order, string $category, array $extra = []) => [
    'key' => "social_commerce.{$key}",
    'type' => $type,
    'default' => $default,
    'label' => "social_commerce::rules.{$key}.label",
    'description' => "social_commerce::rules.{$key}.description",
    'overridable_levels' => $levels,
    'category' => $category,
    'sort_order' => $order,
    ...$extra,
];
$company = ['platform', 'partner', 'plan', 'group', 'company'];

return [
    'key' => 'social_commerce',
    'name' => 'social_commerce::module.name',
    'description' => 'social_commerce::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    // Products, prices and stock are Inventory's (orders from Messenger come with SC-3, through Online orders).
    'requires' => ['inventory'],
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => [
        // See the connected pages and the posts.
        'social_commerce.view',
        // Write posts (text, photos, products), send them for approval, withdraw them.
        'social_commerce.compose',
        // Approve or turn down posts; what one approves goes out at its time.
        'social_commerce.approve',
        // Connect and disconnect the company's Facebook pages, and set their switches (replies, price in public).
        'social_commerce.connect',
        // Read the comments and Messenger chats, answer them, take and finish conversations.
        'social_commerce.inbox',
        // Change what the bot understands and answers.
        'social_commerce.replies',
    ],
    'rules' => [
        // Whether a partner's clients may connect through the house Meta app when the partner has none of its own
        // (the Facebook sign-in then shows the house app's name).
        $rule('house_app_allowed', 'boolean', true, ['platform', 'partner'], 10, 'connection'),
        // Posts wait for someone with social_commerce.approve; off: they go out at their time once sent.
        $rule('posts_need_approval', 'boolean', true, $company, 20, 'posting'),
        $rule('max_images_per_post', 'integer', 10, $company, 30, 'posting', ['schema' => ['minimum' => 1, 'maximum' => 10]]),
        $rule('max_image_mb', 'integer', 4, ['platform', 'partner', 'plan'], 40, 'posting', ['schema' => ['minimum' => 1, 'maximum' => 8]]),
        // A line put under every post, in the post's language: {"en": "...", "bn": "..."}.
        $rule('post_footer', 'json', ['en' => '', 'bn' => ''], $company, 50, 'posting', [
            'schema' => ['type' => 'object', 'maxProperties' => 20, 'additionalProperties' => ['type' => 'string', 'maxLength' => 300]],
        ]),
        // What a product line in a post shows besides the name.
        $rule('show_price', 'boolean', true, $company, 60, 'posting'),
        $rule('show_stock', 'boolean', false, $company, 70, 'posting'),
        // How soon a scheduled post may go out at the earliest (minutes from now).
        $rule('min_schedule_minutes', 'integer', 10, $company, 80, 'posting', ['schema' => ['minimum' => 0, 'maximum' => 1440]]),
        // The bot answers comments and messages (each page also has its own switch).
        $rule('auto_reply', 'boolean', true, $company, 110, 'replies'),
        // A newly connected page's price switch: on, a price goes in the public comment reply; off, to the inbox.
        $rule('price_public', 'boolean', true, $company, 120, 'replies'),
        // The same question in a conversation is not answered again within these minutes.
        $rule('reply_cooldown_minutes', 'integer', 10, $company, 130, 'replies', ['schema' => ['minimum' => 0, 'maximum' => 1440]]),
        // After this many questions the bot did not understand, a person is asked to answer.
        $rule('handoff_after_unknown', 'integer', 1, $company, 140, 'replies', ['schema' => ['minimum' => 1, 'maximum' => 10]]),
        // SC-4: when the keywords do not tell what a customer asks, the AI assistant reads the message
        // (needs the AI assistant module, with its consent; every call counts against ai_assistant.monthly_calls).
        $rule('ai_understanding', 'boolean', false, $company, 145, 'replies'),
        // With AI on: one message with name, phone and address fills those questions of a Messenger order at once.
        $rule('ai_fill_order', 'boolean', true, $company, 146, 'replies'),
        // Customers may order in Messenger (a page also needs its branch and warehouse set).
        $rule('messenger_orders', 'boolean', true, $company, 160, 'orders'),
        // An order being taken is dropped after these hours of silence.
        $rule('draft_hours', 'integer', 24, $company, 170, 'orders', ['schema' => ['minimum' => 1, 'maximum' => 168]]),
        // Most pieces of a product one Messenger order may ask for (more: a person takes it).
        $rule('max_quantity', 'integer', 20, $company, 180, 'orders', ['schema' => ['minimum' => 1, 'maximum' => 1000]]),
        // The customer hears in Messenger when their order is confirmed, sent, delivered or cancelled.
        $rule('notify_order_status', 'boolean', true, $company, 190, 'orders'),
        // Conversations (customers' names and words) are forgotten after these days without news.
        $rule('keep_messages_days', 'integer', 365, ['platform', 'partner', 'plan', 'group', 'company'], 150, 'replies', ['country_specific' => true, 'schema' => ['minimum' => 30, 'maximum' => 3650]]),
    ],
    'menu' => [
        [
            'key' => 'social_commerce',
            'label' => 'social_commerce::module.menu',
            'route' => '/social/posts',
            'icon' => 'megaphone',
            'order' => 57,
            'section' => 'business',
            'children' => [
                ['key' => 'inbox', 'label' => 'social_commerce::module.menu_inbox', 'route' => '/social/inbox', 'permission' => 'social_commerce.inbox'],
                ['key' => 'posts', 'label' => 'social_commerce::module.menu_posts', 'route' => '/social/posts', 'permission' => 'social_commerce.view'],
                ['key' => 'new', 'label' => 'social_commerce::module.menu_new', 'route' => '/social/posts/new', 'permission' => 'social_commerce.compose'],
                ['key' => 'pages', 'label' => 'social_commerce::module.menu_pages', 'route' => '/social/pages', 'permission' => 'social_commerce.view'],
                ['key' => 'replies', 'label' => 'social_commerce::module.menu_replies', 'route' => '/social/replies', 'permission' => 'social_commerce.replies'],
            ],
        ],
    ],
    'quick_actions' => [],
    'events' => ['social_commerce.page_connected', 'social_commerce.post_published', 'social_commerce.post_failed'],
    'notifications' => [
        'social_commerce.post_waiting' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'author', 'page', 'link'],
            'audience' => 'client',
            'path' => '/social/posts?status=pending',
        ],
        'social_commerce.post_failed' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'page', 'reason', 'link'],
            'audience' => 'client',
            'path' => '/social/posts?status=failed',
        ],
        'social_commerce.page_needs_reconnect' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'page', 'link'],
            'audience' => 'client',
            'path' => '/social/pages',
        ],
    ],
    'attention' => [InboxAttention::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => [
        ['key' => 'pages', 'label' => 'social_commerce::module.menu_pages', 'route' => '/social/pages', 'permission' => 'social_commerce.connect'],
        ['key' => 'replies', 'label' => 'social_commerce::module.menu_replies', 'route' => '/social/replies', 'permission' => 'social_commerce.replies'],
    ]],
];
