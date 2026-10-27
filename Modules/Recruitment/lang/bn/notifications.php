<?php

return [
    'templates' => [
        'recruitment_applied' => [
            'subject' => '{{ job }} পদের জন্য নতুন আবেদন',
            'body' => '{{ organization }}-এ {{ job }} পদের জন্য একজন আবেদন করেছেন এবং email নিশ্চিত করেছেন।

CV দেখতে বিজ্ঞাপনটি খুলুন।',
            'action' => 'বিজ্ঞাপনটি খুলুন',
        ],
        'recruitment_confirm' => [
            'subject' => '{{ job }} পদে আপনার আবেদন নিশ্চিত করুন',
            'body' => 'প্রিয় {{ name }},

{{ organization }}-এ {{ job }} পদে আবেদনের জন্য ধন্যবাদ। {{ hours }} ঘণ্টার মধ্যে আপনার email ঠিকানা নিশ্চিত করুন; তার আগে আবেদনটি প্রতিষ্ঠানের কাছে যাবে না।

আপনি আবেদন না করে থাকলে এই mail উপেক্ষা করুন।',
            'action' => 'আমার আবেদন নিশ্চিত করুন',
        ],
        'recruitment_interview_scheduled' => [
            'subject' => '{{ job }} পদের সাক্ষাৎকার: {{ when }}',
            'body' => '{{ organization }}-এ {{ job }} পদের একজন প্রার্থীর সাক্ষাৎকার নিতে আপনাকে বলা হয়েছে।

কখন: {{ when }}
কীভাবে: {{ mode }}
কোথায়: {{ place }}

সাক্ষাৎকারের পর আপনার মতামত দিন।',
            'action' => 'আমার সাক্ষাৎকার খুলুন',
        ],
        'recruitment_interview_invite' => [
            'subject' => '{{ job }} পদে আপনার সাক্ষাৎকার',
            'body' => 'প্রিয় {{ name }},

{{ organization }} আপনাকে {{ job }} পদের সাক্ষাৎকারে আমন্ত্রণ জানাচ্ছে।

কখন: {{ when }}
কীভাবে: {{ mode }}
কোথায়: {{ place }}

সময় সুবিধাজনক না হলে প্রতিষ্ঠানকে জানান।',
            'action' => 'খুলুন',
        ],
        'recruitment_not_selected' => [
            'subject' => '{{ job }} পদে আপনার আবেদন',
            'body' => 'প্রিয় {{ name }},

{{ organization }}-এ {{ job }} পদে আগ্রহের জন্য ধন্যবাদ। ভেবেচিন্তে এবার আমরা আপনার আবেদন নিয়ে আর এগোচ্ছি না।

আপনার জন্য শুভকামনা।',
            'action' => 'খুলুন',
        ],
        'recruitment_offer_to_approve' => [
            'subject' => '{{ job }} পদের একটি চাকরির অফার অনুমোদনের অপেক্ষায়',
            'body' => '{{ organization }}-এ {{ job }} পদের একটি চাকরির অফার আপনার অনুমোদনের অপেক্ষায়।

লেটারটি পড়ে অনুমোদন দিন বা ফেরত পাঠান।',
            'action' => 'বিজ্ঞাপনটি খুলুন',
        ],
        'recruitment_offer_sent' => [
            'subject' => '{{ job }} পদে আপনার চাকরির অফার',
            'body' => 'প্রিয় {{ name }},

{{ organization }} আপনাকে {{ job }} পদে চাকরির প্রস্তাব দিচ্ছে। লেটারটি পড়ে {{ respond_by }}-এর মধ্যে আপনার উত্তর দিন।',
            'action' => 'আমার অফার পড়ুন',
        ],
        'recruitment_offer_answered' => [
            'subject' => '{{ job }} পদের চাকরির অফার: {{ answer }}',
            'body' => '{{ organization }}-এ {{ job }} পদের চাকরির অফারে প্রার্থী উত্তর দিয়েছেন: {{ answer }}।',
            'action' => 'বিজ্ঞাপনটি খুলুন',
        ],
    ],
    'catalog' => [
        'recruitment_applied' => [
            'name' => 'নতুন চাকরির আবেদন',
            'description' => 'কেউ আবেদন নিশ্চিত করলে নিয়োগ পরিচালনাকারীদের কাছে (mail-এ নাম বা যোগাযোগ থাকে না)।',
        ],
        'recruitment_confirm' => [
            'name' => 'চাকরির আবেদন নিশ্চিতকরণ',
            'description' => 'আবেদনকারীর কাছে: email নিশ্চিত করার link।',
        ],
        'recruitment_interview_scheduled' => [
            'name' => 'সাক্ষাৎকার নির্ধারণ (সাক্ষাৎকারগ্রহীতা)',
            'description' => 'যাদের সাক্ষাৎকার নিতে বলা হয়েছে তাদের কাছে।',
        ],
        'recruitment_interview_invite' => [
            'name' => 'সাক্ষাৎকারের আমন্ত্রণ',
            'description' => 'HR চাইলে প্রার্থীর কাছে।',
        ],
        'recruitment_not_selected' => [
            'name' => 'আবেদন আর এগোচ্ছে না',
            'description' => 'HR প্রত্যাখ্যান করে জানাতে চাইলে প্রার্থীর কাছে।',
        ],
        'recruitment_offer_to_approve' => [
            'name' => 'অনুমোদনের অপেক্ষায় চাকরির অফার',
            'description' => 'অফার অনুমোদনের অপেক্ষায় থাকলে নিয়োগের অনুমতি আছে এমনদের কাছে (mail-এ নাম বা বেতন থাকে না)।',
        ],
        'recruitment_offer_sent' => [
            'name' => 'চাকরির অফার',
            'description' => 'প্রার্থীর কাছে, অফার পড়ে উত্তর দেওয়ার link-সহ।',
        ],
        'recruitment_offer_answered' => [
            'name' => 'চাকরির অফারের উত্তর',
            'description' => 'যাঁরা অফার লিখেছেন, অনুমোদন দিয়েছেন আর নিয়োগ পরিচালনা করেন তাঁদের কাছে।',
        ],
    ],
];
