<?php

return [
    'templates' => [
        'projects_task_assigned' => [
            'subject' => 'আপনার জন্য নতুন কাজ: {{ task }}',
            'body' => '{{ by }} আপনাকে {{ project }} ({{ organization }})-এ একটা কাজ দিয়েছেন: {{ task }}। শেষ দিন: {{ due }}।',
            'action' => 'আমার কাজ খুলুন',
        ],
        'projects_task_overdue' => [
            'subject' => 'শেষ দিন পেরোনো কাজ: {{ task }}',
            'body' => '{{ project }} ({{ organization }})-এর "{{ task }}" কাজটার শেষ দিন ছিল {{ due }}, এখনো শেষ হয়নি।',
            'action' => 'আমার কাজ খুলুন',
        ],
    ],
    'catalog' => [
        'projects_task_assigned' => [
            'name' => 'আপনাকে দেওয়া কাজ',
            'description' => 'প্রজেক্টের কোনো কাজে যোগ করা প্রত্যেককে।',
        ],
        'projects_task_overdue' => [
            'name' => 'শেষ দিন পেরোনো কাজ',
            'description' => 'কাজের শেষ দিন পেরোলে আর শেষ না হলে, তার লোকদের একবার।',
        ],
    ],
];
