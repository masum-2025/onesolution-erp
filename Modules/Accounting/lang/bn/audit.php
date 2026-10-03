<?php

// Readable audit actions: "accounting.journal_posted" -> "journal_posted" (AuditQuery::label).

return [
    'books_set_up' => 'হিসাবের খাতা চালু করা হয়েছে',
    'account_created' => 'হিসাব যোগ করা হয়েছে',
    'account_updated' => 'হিসাব বদলানো হয়েছে',
    'fiscal_year_created' => 'অর্থবছর যোগ করা হয়েছে',
    'period_closed' => 'হিসাবকাল বন্ধ করা হয়েছে',
    'period_reopened' => 'হিসাবকাল আবার খোলা হয়েছে',
    'posting_account_set' => 'পোস্টিং হিসাব বেছে নেওয়া হয়েছে',
    'journal_drafted' => 'জাবেদা লেখা হয়েছে',
    'journal_updated' => 'জাবেদা বদলানো হয়েছে',
    'journal_deleted' => 'খসড়া জাবেদা মুছে ফেলা হয়েছে',
    'journal_submitted' => 'জাবেদা অনুমোদনের জন্য পাঠানো হয়েছে',
    'journal_withdrawn' => 'জাবেদা অনুমোদন থেকে ফিরিয়ে নেওয়া হয়েছে',
    'journal_rejected' => 'জাবেদা প্রত্যাখ্যাত হয়েছে',
    'journal_posted' => 'জাবেদা পোস্ট হয়েছে',
    'journal_reversed' => 'জাবেদা উল্টানো হয়েছে',
];
