<?php

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
        // Connect and disconnect the company's Facebook pages.
        'social_commerce.connect',
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
                ['key' => 'posts', 'label' => 'social_commerce::module.menu_posts', 'route' => '/social/posts', 'permission' => 'social_commerce.view'],
                ['key' => 'new', 'label' => 'social_commerce::module.menu_new', 'route' => '/social/posts/new', 'permission' => 'social_commerce.compose'],
                ['key' => 'pages', 'label' => 'social_commerce::module.menu_pages', 'route' => '/social/pages', 'permission' => 'social_commerce.view'],
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
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => [
        ['key' => 'pages', 'label' => 'social_commerce::module.menu_pages', 'route' => '/social/pages', 'permission' => 'social_commerce.connect'],
    ]],
];
