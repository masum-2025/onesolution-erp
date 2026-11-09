<?php

/*
| SC-2: the bot's first words and answers for a company (copied into its
| social_reply_templates the first time they are needed; the company then
| changes them on the Replies screen). A word's language is the language
| of the answer: Banglish ("dam koto", "pp") is under bn.
|
| Placeholders: {product} {price} {stock} {delivery} {page}.
*/

return [
    'price' => [
        'keywords' => [
            'bn' => ['দাম', 'দামটা', 'কত', 'মূল্য', 'রেট', 'dam', 'daam', 'damta', 'koto', 'koto?', 'pp', 'price koto', 'rate koto'],
            'en' => ['price', 'prices', 'cost', 'how much', 'rate', 'pricing'],
        ],
        'answers' => [
            'bn' => '{product}: দাম {price}। {stock}',
            'en' => '{product}: {price}. {stock}',
        ],
    ],
    'stock' => [
        'keywords' => [
            'bn' => ['আছে', 'আছে?', 'স্টক', 'পাওয়া যাবে', 'ache', 'ase', 'achhe', 'stock ache', 'available ache', 'paowa jabe'],
            'en' => ['stock', 'available', 'in stock', 'availability'],
        ],
        'answers' => [
            'bn' => '{product}: {stock}।',
            'en' => '{product}: {stock}.',
        ],
    ],
    'delivery' => [
        'keywords' => [
            'bn' => ['ডেলিভারি', 'চার্জ', 'কুরিয়ার', 'delivery charge koto', 'charge koto', 'courier'],
            'en' => ['delivery', 'shipping', 'delivery charge', 'shipping cost'],
        ],
        'answers' => [
            'bn' => 'ডেলিভারি চার্জ: {delivery}।',
            'en' => 'Delivery: {delivery}.',
        ],
    ],
    'order' => [
        'keywords' => [
            'bn' => ['অর্ডার', 'কিভাবে', 'কীভাবে', 'নিব', 'নেব', 'কিনব', 'order korbo', 'kivabe', 'nibo', 'kinbo'],
            'en' => ['order', 'buy', 'purchase', 'how to order'],
        ],
        'answers' => [
            'bn' => 'অর্ডার করতে আপনার নাম, মোবাইল নম্বর আর পুরো ঠিকানা ইনবক্সে পাঠান।',
            'en' => 'To order, send us your name, mobile number and full address in a message.',
        ],
    ],
    // Public line under a comment when the price went to the customer's inbox.
    'notice' => [
        'keywords' => ['bn' => [], 'en' => []],
        'answers' => [
            'bn' => 'দাম ইনবক্সে পাঠিয়েছি ✅',
            'en' => 'We sent the price to your inbox ✅',
        ],
    ],
    // When the bot did not understand: a person will answer.
    'handoff' => [
        'keywords' => ['bn' => [], 'en' => []],
        'answers' => [
            'bn' => 'ধন্যবাদ! আমাদের একজন শিগগির উত্তর দেবেন।',
            'en' => 'Thank you! One of us will answer you soon.',
        ],
    ],
];
