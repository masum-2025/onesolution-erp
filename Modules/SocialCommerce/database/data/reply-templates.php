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
    // Taking an order in Messenger (SC-3): yes, and stop.
    'confirm' => [
        'keywords' => [
            'bn' => ['হ্যাঁ', 'হ্যা', 'জি', 'জ্বি', 'ঠিক আছে', 'নিশ্চিত', 'ha', 'haa', 'hya', 'ji', 'jii', 'thik ache', 'confirm koro'],
            'en' => ['yes', 'yeah', 'ok', 'okay', 'confirm', 'sure'],
        ],
        'answers' => ['bn' => '', 'en' => ''],
    ],
    'cancel' => [
        'keywords' => [
            'bn' => ['বাতিল', 'না', 'লাগবে না', 'থাক', 'batil', 'lagbe na', 'thak', 'na'],
            'en' => ['cancel', 'stop', 'no', 'never mind'],
        ],
        'answers' => ['bn' => '', 'en' => ''],
    ],
    'ask_product' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => [
        'bn' => "কোন পণ্যটি নেবেন? নম্বর লিখুন:\n{products}",
        'en' => "Which product would you like? Write its number:\n{products}",
    ]],
    'ask_quantity' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => [
        'bn' => '{product} কয়টি নেবেন? (১ থেকে {max})',
        'en' => 'How many {product}? (1 to {max})',
    ]],
    'ask_name' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'আপনার নাম লিখুন।', 'en' => 'Your name, please.']],
    'ask_phone' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'আপনার মোবাইল নম্বর লিখুন।', 'en' => 'Your mobile number, please.']],
    'ask_address' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'পুরো ডেলিভারি ঠিকানা লিখুন (বাসা, রাস্তা, এলাকা)।', 'en' => 'Your full delivery address, please (house, road, area).']],
    'ask_zone' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => [
        'bn' => "ডেলিভারি কোথায়? নম্বর লিখুন:\n{zones}",
        'en' => "Where should we deliver? Write its number:\n{zones}",
    ]],
    'bad_phone' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'নম্বরটি ঠিক মনে হচ্ছে না। আবার লিখুন, যেমন 01712345678।', 'en' => 'That number does not look right. Write it again, e.g. 01712345678.']],
    'short_stock' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'দুঃখিত, {product} এখন {available}টি আছে। কয়টি নেবেন?', 'en' => 'Sorry, we have {available} {product} now. How many would you like?']],
    'summary' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => [
        'bn' => "আপনার অর্ডার:\n{product} × {quantity}\nডেলিভারি: {zone} ({delivery})\nমোট: {total} (ক্যাশ অন ডেলিভারি)\n{name}, {phone}\n{address}\n\nঠিক থাকলে \"হ্যাঁ\" লিখুন, বাতিল করতে \"বাতিল\"।",
        'en' => "Your order:\n{product} × {quantity}\nDelivery: {zone} ({delivery})\nTotal: {total} (cash on delivery)\n{name}, {phone}\n{address}\n\nWrite \"yes\" to confirm, or \"cancel\".",
    ]],
    'placed' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => [
        'bn' => 'ধন্যবাদ! আপনার অর্ডার নম্বর {number}। আমরা শিগগির ফোন করে নিশ্চিত করব।',
        'en' => 'Thank you! Your order number is {number}. We will call you soon to confirm.',
    ]],
    'cancelled' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'ঠিক আছে, অর্ডারটি বাতিল করা হলো।', 'en' => 'All right, the order is cancelled.']],
    // The customer's order news (Online orders' steps).
    'status_confirmed' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'আপনার অর্ডার {number} নিশ্চিত হয়েছে। মোট {total}।', 'en' => 'Your order {number} is confirmed. Total {total}.']],
    'status_shipped' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'আপনার অর্ডার {number} পাঠানো হয়েছে। {courier} {tracking}', 'en' => 'Your order {number} is on its way. {courier} {tracking}']],
    'status_delivered' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'আপনার অর্ডার {number} পৌঁছে গেছে। আমাদের সাথে থাকার জন্য ধন্যবাদ!', 'en' => 'Your order {number} has been delivered. Thank you for shopping with us!']],
    'status_cancelled' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'আপনার অর্ডার {number} বাতিল হয়েছে। প্রশ্ন থাকলে এখানে লিখুন।', 'en' => 'Your order {number} was cancelled. Write to us here if you have a question.']],
    // SC-5a: the payment link right after the order, and thanks once it is paid.
    'pay_link' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => [
        'bn' => "অর্ডার পাঠানোর আগে {amount} অনলাইনে দিন ({hours} ঘণ্টার মধ্যে):\n{link}",
        'en' => "Please pay {amount} online before we send your order (within {hours} hours):\n{link}",
    ]],
    'status_paid' => ['keywords' => ['bn' => [], 'en' => []], 'answers' => ['bn' => 'ধন্যবাদ! অর্ডার {number}-এর {amount} পাওয়া গেছে।', 'en' => 'Thank you! We received {amount} for order {number}.']],
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
