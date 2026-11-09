<?php

return [
    'templates' => [
        'social_commerce_post_waiting' => [
            'subject' => 'A Facebook post for {{ page }} waits for your approval',
            'body' => '{{ author }} wrote a post for the Facebook page {{ page }} of {{ organization }}. Approve it to send it out, or turn it down with a reason.',
            'action' => 'See the posts waiting',
        ],
        'social_commerce_post_failed' => [
            'subject' => 'Your Facebook post for {{ page }} did not go out',
            'body' => 'Facebook did not take your post for {{ page }} ({{ organization }}). {{ reason }}',
            'action' => 'Open the post',
        ],
        'social_commerce_page_needs_reconnect' => [
            'subject' => 'Connect the Facebook page {{ page }} again',
            'body' => 'Facebook no longer accepts the sign-in of the page {{ page }} for {{ organization }}. Posts cannot go out until someone who manages the page connects it again.',
            'action' => 'Open the pages',
        ],
    ],
    'catalog' => [
        'social_commerce_post_waiting' => [
            'name' => 'Facebook post waiting for approval',
            'description' => 'To the people who approve posts, when a post is sent for approval.',
        ],
        'social_commerce_post_failed' => [
            'name' => 'Facebook post did not go out',
            'description' => 'To the writer of a post Facebook did not take.',
        ],
        'social_commerce_page_needs_reconnect' => [
            'name' => 'Facebook page needs a new sign-in',
            'description' => 'To the people who connect pages, when Facebook stops accepting a page\'s sign-in.',
        ],
    ],
];
