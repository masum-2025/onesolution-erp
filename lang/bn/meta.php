<?php

// Meta (Facebook): partner-এর নিজের app, Graph API, Meta-র callback (SC-1)।
return [
    'errors' => [
        'token_invalid' => 'Facebook আর এই সংযোগ মানছে না। পেজটি আবার connect করুন। :detail',
        'permission_missing' => 'এ কাজের জন্য দরকারি অনুমতি Facebook দেয়নি। পেজটি আবার connect করে চাওয়া সব অনুমতি দিন। :detail',
        'rate_limited' => 'Facebook একটু ধীরে চলতে বলেছে। কয়েক মিনিট পর আবার চেষ্টা করুন। :detail',
        'unreachable' => 'এখন Facebook-এ পৌঁছানো যাচ্ছে না। কয়েক মিনিট পর আবার চেষ্টা করুন।',
        'rejected' => 'Facebook এটি নেয়নি। :detail',
        'no_app' => 'আপনার অ্যাকাউন্টে এখনো Facebook সেট করা হয়নি। আপনার সেবাদাতাকে তাদের Meta app যোগ করতে বলুন।',
        'app_check_failed' => 'এই app id আর secret একসাথে Meta মানেনি। developers.facebook.com থেকে (App settings → Basic) দুটোই আবার কপি করুন।',
        'version_conflict' => 'এর মধ্যে অন্য কেউ এটি বদলেছেন। পাতাটি আবার খুলে চেষ্টা করুন।',
        'nothing_pending' => 'অনুমোদনের অপেক্ষায় কোনো পরিবর্তন নেই।',
        'own_change' => 'পরিবর্তনটি আপনি চেয়েছেন, তাই অন্য একজন partner owner-কে অনুমোদন দিতে হবে।',
        'bad_signed_request' => 'অনুরোধটি Meta-র স্বাক্ষর করা নয়।',
    ],
    'validation' => [
        'app_id' => 'App id ৬ থেকে ৩০ অঙ্কের একটি সংখ্যা (App settings → Basic)।',
        'app_secret' => 'App secret ১৬ থেকে ৬৪টি অক্ষর ও অঙ্ক (App settings → Basic → Show)।',
    ],
    'messages' => [
        'needs_approval' => 'সংরক্ষিত হয়েছে। ব্যবহারের আগে অন্য একজন partner owner-কে অনুমোদন দিতে হবে।',
        'waits' => 'সংরক্ষিত হয়েছে। অনুমোদনের জন্য দ্বিতীয় কোনো owner নেই, তাই অপেক্ষার সময় শেষে এটি চালু হবে।',
        'approved' => 'অনুমোদিত। আপনার ক্লায়েন্টরা এখন এই app দিয়ে connect করবেন।',
        'rejected' => 'পরিবর্তনটি বাদ দেওয়া হয়েছে।',
    ],
    'deletion_done' => 'এই app-এর মাধ্যমে রাখা আপনার Facebook তথ্য মুছে ফেলা হয়েছে।',
];
