<?php

return [

    'errors' => [
        'module_not_found' => 'এই মডিউলটি নেই। মডিউলের নাম যাচাই করুন।',
        'module_disabled' => 'আপনার প্রতিষ্ঠানে :module বন্ধ আছে। চালু করতে একজন অ্যাডমিনকে বলুন।',
        'not_in_plan' => ':module আপনার প্ল্যানে নেই। ব্যবহার করতে প্ল্যান আপগ্রেড করুন।',
        'sector_not_allowed' => ':module এই ব্যবসার ধরনের জন্য পাওয়া যায় না।',
        'consent_required' => ':module চালুর আগে একজন অ্যাডমিনের সম্মতি লাগবে। আগে সম্মতি দিন।',
        'locked_by_parent' => ':module লক করে রেখেছে :organization। পরিবর্তনের জন্য তাদের বলুন।',
        'core_module' => ':module মূল প্ল্যাটফর্মের অংশ, এটি বন্ধ করা যায় না।',
        'dependents_need_confirmation' => ':module বন্ধ করলে এগুলোও বন্ধ হবে: :modules। চালিয়ে যেতে confirm=true পাঠান।',
        'consent_not_applicable' => ':module-এর জন্য সম্মতির প্রয়োজন নেই।',
        'consent_not_found' => 'এই স্তরে প্রত্যাহার করার মতো কোনো সক্রিয় সম্মতি নেই।',
        'purge_confirm_mismatch' => 'নিশ্চিত করতে মডিউলের কী হুবহু লিখুন: :key',
        'purge_requires_disabled' => 'তথ্য মোছার আগে :module বন্ধ করুন।',
        'purge_already_pending' => 'এই মডিউলের তথ্য মোছা ইতিমধ্যেই নির্ধারিত আছে। বদলাতে আগে সেটি বাতিল করুন।',
        'purge_not_found' => 'এই মডিউলের তথ্য মোছার কোনো নির্ধারিত অনুরোধ নেই।',
    ],

    'reasons' => [
        'enabled' => 'চালু',
        'not_in_plan' => 'বর্তমান প্ল্যানে নেই',
        'sector_not_allowed' => 'এই খাতের জন্য পাওয়া যায় না',
        'consent_missing' => 'অ্যাডমিনের সম্মতির অপেক্ষায়',
        'not_enabled' => 'বন্ধ (এখনো চালু করা হয়নি)',
        'disabled' => 'বন্ধ করা হয়েছে',
        'locked_disabled' => 'বন্ধ ও লক করা',
        'dependency_disabled' => 'এই মডিউলগুলো চালু থাকা দরকার: :modules',
    ],

    'messages' => [
        'consent_revoked' => 'সম্মতি প্রত্যাহার করা হয়েছে। মডিউলটি এখন বন্ধ।',
        'purge_scheduled' => ':date তারিখে তথ্য মোছা নির্ধারিত হয়েছে। তার আগ পর্যন্ত বাতিল করতে পারবেন।',
    ],

];
