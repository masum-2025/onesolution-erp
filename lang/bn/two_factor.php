<?php

return [
    'errors' => [
        'invalid_code' => 'কোডটি সঠিক নয়। অ্যাপটি দেখুন, অথবা একটি recovery কোড ব্যবহার করুন।',
        'challenge_ended' => 'সাইন ইনে অনেক সময় লেগেছে বা অনেকবার চেষ্টা হয়েছে। আবার পাসওয়ার্ড দিন।',
        'passkey_failed' => 'Passkey গ্রহণ করা যায়নি। আবার চেষ্টা করুন, অথবা অন্য উপায়ে সাইন ইন করুন।',
        'passkey_unavailable' => 'Passkey শুধু নিরাপদ (https) ঠিকানায় কাজ করে।',
        'already_enabled' => 'Authenticator অ্যাপ আগেই চালু আছে। নতুনটি চালু করতে আগে এটি সরান।',
        'not_started' => 'আগে authenticator অ্যাপ চালু করা শুরু করুন।',
        'still_required' => 'আপনার প্রতিষ্ঠানে দুই ধাপে সাইন ইন বাধ্যতামূলক। এটি সরানোর আগে অন্য একটি উপায় (অ্যাপ বা passkey) যোগ করুন।',
        'step_up_required' => 'এই কাজের জন্য দ্বিতীয় ধাপ দিয়ে নিশ্চিত করুন যে এটি আপনি।',
        'reset_self' => 'নিজের দুই ধাপে সাইন ইন এখান থেকে রিসেট করা যায় না। একটি recovery কোড ব্যবহার করুন।',
        'reset_same_person' => 'এই রিসেট অন্য একজন admin-কে অনুমোদন করতে হবে।',
        'reset_elsewhere' => 'এই ব্যক্তি অন্য প্রতিষ্ঠানেও কাজ করেন, তাই এখান থেকে তাঁর সাইন ইন রিসেট করা যায় না। তিনি recovery কোড ব্যবহার করতে পারেন, অথবা সেবাদাতার কাছে চাইতে পারেন।',
        'reset_nothing' => 'এই ব্যক্তি দুই ধাপে সাইন ইন চালু করেননি।',
        'reset_pending' => 'এই ব্যক্তির একটি রিসেট আগেই অনুমোদনের অপেক্ষায় আছে।',
        'reset_not_open' => 'এই রিসেটের সিদ্ধান্ত আগেই হয়ে গেছে বা মেয়াদ শেষ।',
    ],
    'messages' => [
        'totp_enabled' => 'Authenticator অ্যাপ চালু হয়েছে। Recovery কোডগুলো নিরাপদ জায়গায় রাখুন।',
        'totp_disabled' => 'Authenticator অ্যাপ সরানো হয়েছে।',
        'codes_regenerated' => 'নতুন recovery কোড তৈরি হয়েছে। পুরোনোগুলো আর কাজ করবে না।',
        'passkey_added' => 'Passkey যোগ হয়েছে।',
        'passkey_renamed' => 'Passkey-র নাম বদলানো হয়েছে।',
        'passkey_removed' => 'Passkey সরানো হয়েছে।',
        'confirmed' => 'নিশ্চিত হয়েছে।',
        'reset_requested' => 'রিসেটের অনুরোধ করা হয়েছে। অন্য একজন admin-কে অনুমোদন করতে হবে।',
        'reset_approved' => 'দুই ধাপে সাইন ইন রিসেট হয়েছে। ব্যক্তিটি পরের সাইন ইনে আবার চালু করবেন।',
        'reset_rejected' => 'রিসেট বাতিল করা হয়েছে।',
    ],
    'methods' => [
        'totp' => 'Authenticator অ্যাপ',
        'recovery_code' => 'Recovery কোড',
        'passkey' => 'Passkey',
    ],
];
