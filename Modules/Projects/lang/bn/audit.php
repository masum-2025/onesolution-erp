<?php

// Audit log labels for "projects.*" actions (AuditQuery::label).

return [
    'template_added' => 'প্রজেক্ট টেমপ্লেট যোগ',
    'template_changed' => 'প্রজেক্ট টেমপ্লেট বদল',
    'project_opened' => 'প্রজেক্ট খোলা',
    'project_changed' => 'প্রজেক্ট বদল',
    'project_start' => 'প্রজেক্ট শুরু',
    'project_hold' => 'প্রজেক্ট স্থগিত',
    'project_resume' => 'প্রজেক্ট আবার চালু',
    'project_finish' => 'প্রজেক্ট শেষ',
    'project_cancel' => 'প্রজেক্ট বাতিল',
    'project_reopen' => 'প্রজেক্ট আবার খোলা',
    'task_added' => 'কাজ যোগ',
    'task_changed' => 'কাজ বদল',
    'task_moved' => 'কাজের অবস্থা বদল',
    'task_assigned' => 'কাজের লোক বদল',
    'tasks_ordered' => 'কাজের ক্রম বদল',
    'quote_linked' => 'প্রজেক্টের কোটেশন বানানো',
    'invoice_linked' => 'প্রজেক্টের ইনভয়েস বানানো',
    'client_to_crm' => 'প্রজেক্টের ক্লায়েন্ট সিআরএম-এ যোগ',
    'request_received' => 'সংযুক্ত ক্লায়েন্টের নবায়নের অনুরোধ এসেছে',
    'request_cancelled' => 'ক্লায়েন্ট নবায়নের অনুরোধ বাতিল করেছে',
    'request_accepted' => 'নবায়নের অনুরোধ গৃহীত: প্রজেক্ট খোলা',
    'request_declined' => 'নবায়নের অনুরোধ প্রত্যাখ্যাত',
    'result_sent' => 'ক্লায়েন্টকে অডিটের ফলাফল পাঠানো',
    'quote_sent' => 'সংযুক্ত ক্লায়েন্টকে কোটেশন পাঠানো',
    'invoice_sent' => 'সংযুক্ত ক্লায়েন্টকে ইনভয়েস পাঠানো',
    'quote_accepted' => 'ক্লায়েন্ট কোটেশন গ্রহণ করেছে',
    'quote_declined' => 'ক্লায়েন্ট কোটেশন প্রত্যাখ্যান করেছে',
];
