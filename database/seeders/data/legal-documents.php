<?php

/*
|--------------------------------------------------------------------------
| The platform's own legal documents (data, not code)
|--------------------------------------------------------------------------
|
| The default terms, privacy notice and data processing agreement for every
| partner that has not published its own. `php artisan legal:sync` publishes
| a new platform version whenever the text here changes (published versions
| never change). Plain text: a line starting with "# " is a heading, a blank
| line starts a new paragraph.
|
| PLACEHOLDER TEXT. It must be replaced by text reviewed by a lawyer for each
| country before real clients accept it.
|
*/

return [
    'terms' => [
        'title' => ['en' => 'Terms of service', 'bn' => 'সেবার শর্তাবলি'],
        'summary' => 'Placeholder terms (to be reviewed by a lawyer).',
        'body' => [
            'en' => "# About these terms\nThese terms apply to your organization's use of the service. PLACEHOLDER: to be replaced by text reviewed by a lawyer.\n\n# Your data\nYour organization owns its data. You can export all of it at any time, and move to another provider with it.\n\n# Payment\nFees, billing periods and payment terms are those of your plan.\n\n# Ending the service\nYou can stop at any time. Your data stays available for export for the period the rules set.",
            'bn' => "# এই শর্তাবলি সম্পর্কে\nআপনার প্রতিষ্ঠানের এই সেবা ব্যবহারে এই শর্তাবলি প্রযোজ্য। প্লেসহোল্ডার: আইনজীবীর যাচাই করা লেখা দিয়ে বদলাতে হবে।\n\n# আপনার ডেটা\nআপনার প্রতিষ্ঠানের ডেটার মালিক আপনার প্রতিষ্ঠান। যেকোনো সময় সব ডেটা এক্সপোর্ট করতে পারেন, আর ডেটা নিয়ে অন্য সেবাদাতার কাছে যেতে পারেন।\n\n# পরিশোধ\nফি, বিলের মেয়াদ আর পরিশোধের শর্ত আপনার প্ল্যান অনুযায়ী।\n\n# সেবা বন্ধ করা\nযেকোনো সময় বন্ধ করতে পারেন। নিয়মে যতদিন বলা আছে, ততদিন আপনার ডেটা এক্সপোর্টের জন্য থাকবে।",
        ],
    ],
    'privacy' => [
        'title' => ['en' => 'Privacy notice', 'bn' => 'গোপনীয়তা নীতি'],
        'summary' => 'Placeholder privacy notice (to be reviewed by a lawyer).',
        'body' => [
            'en' => "# What we keep\nNames, email addresses and what your organization records in the service. PLACEHOLDER: to be replaced by text reviewed by a lawyer.\n\n# Why\nOnly to run the service for your organization.\n\n# Your rights\nAsk your organization's administrator to see, correct or remove your data.",
            'bn' => "# আমরা কী রাখি\nনাম, ইমেইল ঠিকানা আর আপনার প্রতিষ্ঠান এই সেবায় যা রেকর্ড করে। প্লেসহোল্ডার: আইনজীবীর যাচাই করা লেখা দিয়ে বদলাতে হবে।\n\n# কেন\nশুধু আপনার প্রতিষ্ঠানের জন্য সেবা চালাতে।\n\n# আপনার অধিকার\nআপনার ডেটা দেখতে, ঠিক করতে বা মুছতে আপনার প্রতিষ্ঠানের অ্যাডমিনকে বলুন।",
        ],
    ],
    'dpa' => [
        'title' => ['en' => 'Data processing agreement', 'bn' => 'ডেটা প্রসেসিং চুক্তি'],
        'summary' => 'Placeholder data processing agreement (to be reviewed by a lawyer).',
        'body' => [
            'en' => "# Roles\nYour organization controls its data; the service provider processes it on your organization's instructions. PLACEHOLDER: to be replaced by text reviewed by a lawyer.\n\n# Security\nData is kept separate from other organizations, access is logged, and support staff can look inside only with your approval, read-only and for a limited time.\n\n# Sub-processors\nHosting, email and SMS providers, listed on request.\n\n# At the end\nYou can export all data; it is deleted only when you ask or the rules say so.",
            'bn' => "# ভূমিকা\nআপনার প্রতিষ্ঠান তার ডেটা নিয়ন্ত্রণ করে; সেবাদাতা আপনার প্রতিষ্ঠানের নির্দেশে তা প্রসেস করে। প্লেসহোল্ডার: আইনজীবীর যাচাই করা লেখা দিয়ে বদলাতে হবে।\n\n# নিরাপত্তা\nডেটা অন্য প্রতিষ্ঠানের ডেটা থেকে আলাদা থাকে, প্রবেশ লগে থাকে, আর সাপোর্ট কর্মীরা শুধু আপনার অনুমোদনে, শুধু দেখার জন্য, নির্দিষ্ট সময় ভেতরে দেখতে পারেন।\n\n# সাব-প্রসেসর\nহোস্টিং, ইমেইল আর এসএমএস সেবাদাতা; চাইলে তালিকা দেওয়া হয়।\n\n# শেষে\nআপনি সব ডেটা এক্সপোর্ট করতে পারেন; আপনি চাইলে বা নিয়মে বললে তবেই মোছা হয়।",
        ],
    ],
];
