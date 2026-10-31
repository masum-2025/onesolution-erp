<?php

return [
    'templates' => [
        'compliance_expiring' => [
            'subject' => '{{ organization }}-এর {{ count }}টা কারখানার অডিট নবায়ন করতে হবে',
            'body' => '{{ organization }}-এর এই অডিটগুলোর মেয়াদ শিগগির শেষ হবে বা শেষ হয়ে গেছে:

{{ audits }}

নবায়নের পরিকল্পনা করতে রেজিস্টার খুলুন।',
            'action' => 'অডিট রেজিস্টার খুলুন',
        ],
        'compliance_result_received' => [
            'subject' => '{{ other }}-এর পাঠানো অডিটের ফলাফল: {{ organization }}-এর {{ service }}',
            'body' => '{{ other }} {{ organization }}-এর {{ service }}-এর ফলাফল পাঠিয়েছে। মিলিয়ে দেখে রেজিস্টারে গ্রহণ করুন, অথবা কারণসহ প্রত্যাখ্যান করুন।',
            'action' => 'ফলাফল দেখুন',
        ],
        'compliance_quote_received' => [
            'subject' => '{{ other }}-এর কোটেশন {{ number }}',
            'body' => '{{ other }} {{ organization }}-এর জন্য {{ number }} কোটেশন পাঠিয়েছে। দেখে গ্রহণ বা প্রত্যাখ্যান করুন; তাদের আপনার উত্তর জানানো হবে।',
            'action' => 'নবায়নের তালিকা খুলুন',
        ],
        'compliance_invoice_received' => [
            'subject' => '{{ other }}-এর ইনভয়েস {{ number }}',
            'body' => '{{ other }} {{ organization }}-এর জন্য {{ number }} ইনভয়েস পাঠিয়েছে। দেখে নিন; হিসাব রাখলে এক চাপে খসড়া bill হিসেবে তুলতে পারবেন।',
            'action' => 'নবায়নের তালিকা খুলুন',
        ],
        'compliance_renewal_answered' => [
            'subject' => '{{ other }} আপনার নবায়নের অনুরোধের উত্তর দিয়েছে: {{ status }}',
            'body' => '{{ organization }}-এর {{ service }} নবায়ন: {{ status }}। নির্ধারিত দিন: {{ planned }}।',
            'action' => 'নবায়নের তালিকা খুলুন',
        ],
    ],
    'catalog' => [
        'compliance_expiring' => [
            'name' => 'নবায়নের অপেক্ষায় অডিট',
            'description' => 'অডিটের মেয়াদ শেষের আগে রিমাইন্ডারের দিনগুলোতে আর মেয়াদ পেরোলে, যাঁরা কমপ্লায়েন্স পরিচালনা করেন তাঁদের কাছে।',
        ],
        'compliance_result_received' => [
            'name' => 'প্রোভাইডারের পাঠানো অডিটের ফলাফল',
            'description' => 'সংযুক্ত প্রোভাইডার ফলাফল পাঠালে, যাঁরা ফলাফল রেজিস্টারে গ্রহণ করেন তাঁদের কাছে।',
        ],
        'compliance_quote_received' => [
            'name' => 'প্রোভাইডারের কোটেশন',
            'description' => 'সংযুক্ত প্রোভাইডার কোটেশন পাঠালে, যাঁরা কোটেশনের উত্তর দেন তাঁদের কাছে।',
        ],
        'compliance_invoice_received' => [
            'name' => 'প্রোভাইডারের ইনভয়েস',
            'description' => 'সংযুক্ত প্রোভাইডার নতুন ইনভয়েস পাঠালে, যাঁরা প্রোভাইডারের ইনভয়েস দেখেন তাঁদের কাছে।',
        ],
        'compliance_renewal_answered' => [
            'name' => 'নবায়নের অনুরোধের উত্তর',
            'description' => 'প্রোভাইডার অনুরোধ গ্রহণ, প্রত্যাখ্যান বা শেষ করলে, যাঁরা নবায়নের অনুরোধ পাঠান তাঁদের কাছে।',
        ],
    ],
    'expires_on' => ':date-এ মেয়াদ শেষ',
    'expired_on' => ':date-এ মেয়াদ শেষ হয়েছে',
];
