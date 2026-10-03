<?php

// Messages HRM sends (NotificationCatalog: module notifications).
return [
    'templates' => [
        'hrm_document_expiring' => [
            'subject' => '{{ organization }}-এ {{ count }}টি কর্মীর ডকুমেন্ট নবায়ন করতে হবে',
            'body' => '{{ organization }}-এর এই ডকুমেন্টগুলোর মেয়াদ শিগগির শেষ হবে বা শেষ হয়ে গেছে:

{{ documents }}

নতুন কপি চেয়ে নিয়ে প্রত্যেক কর্মীর ডকুমেন্টে আপলোড করুন।',
            'action' => 'এইচআর খুলুন',
        ],
    ],
    'catalog' => [
        'hrm_document_expiring' => ['name' => 'কর্মীর ডকুমেন্টের মেয়াদ শেষ হচ্ছে', 'description' => 'কর্মী পরিচালনাকারী ক্লায়েন্টদের কাছে, যখন ডকুমেন্টের মেয়াদ শিগগির শেষ হবে বা শেষ হয়ে গেছে।'],
    ],
    'expires_on' => ':date মেয়াদ শেষ হবে',
    'expired_on' => ':date মেয়াদ শেষ হয়েছে',
];
