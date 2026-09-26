<?php

return [

    'errors' => [
        'invoice_not_found' => 'এই ইনভয়েসটি নেই, অথবা আপনার দেখার অনুমতি নেই।',
        'plan_not_found' => 'এই প্ল্যানটি নেই।',
        'not_payable' => 'শুধু অপরিশোধিত ইনভয়েসকেই পরিশোধিত হিসেবে চিহ্নিত করা যায়।',
        'not_creditable' => 'এটিতে ক্রেডিট দেওয়া যাবে না: এটি ক্রেডিট নোট, অথবা পুরোটা আগেই ক্রেডিট হয়েছে।',
        'credit_too_large' => 'ক্রেডিট ১ থেকে :remaining (:currency, কর বাদে) এর মধ্যে হতে হবে, এই ইনভয়েসে যতটুকু বাকি আছে।',
        'nothing_to_pay' => ':currency-তে পরিশোধ করার মতো কোনো কমিশন নেই।',
        'role_not_allowed' => 'বিলিং শুধু মালিক আর বিলিং কর্মীরা দেখতে পারেন।',
        'top_level_only' => 'বিলিং :organization-এর। যিনি এটি দেখাশোনা করেন তাঁকে বলুন।',
        'duplicate_price' => 'এই মুদ্রা আর মেয়াদের দাম আগেই দেওয়া আছে।',
    ],

    'messages' => [
        'plan_created' => '":name" প্ল্যান তৈরি হয়েছে।',
        'plan_updated' => '":name" প্ল্যান সংরক্ষণ হয়েছে।',
        'plan_archived' => '":name" প্ল্যান আর্কাইভ হয়েছে। যে ক্লায়েন্টরা এতে আছেন তাঁরা থাকবেন।',
    ],

    'lines' => [
        'wholesale_client' => ':client: :plan প্ল্যান, :month',
        'wholesale_seats' => ':client: :plan প্ল্যান, কর্মী-ব্যবহারকারী, :month',
        'subscription_monthly' => ':plan প্ল্যান, মাসিক, :from থেকে :to',
        'subscription_yearly' => ':plan প্ল্যান, বার্ষিক, :from থেকে :to',
        'credit' => ':number ইনভয়েসের ক্রেডিট',
    ],

    'run' => [
        'no_wholesale_price' => ':client-এর :plan প্ল্যানের :currency-তে হোলসেল দাম নেই',
        'no_client_price' => ':client-এর :currency-তে :period দাম নেই; প্ল্যানে দাম দিন',
    ],
];
