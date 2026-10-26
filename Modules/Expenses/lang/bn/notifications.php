<?php

return array (
  'templates' => 
  array (
    'expenses_submitted' => 
    array (
      'subject' => '{{ employee }}-এর খরচের দাবি {{ number }} অনুমোদনের অপেক্ষায়',
      'body' => '{{ organization }}-এর {{ employee }} খরচের দাবি {{ number }} ({{ amount }}) পাঠিয়েছেন।

রসিদ দেখে সিদ্ধান্ত দিতে খুলুন।',
      'action' => 'অনুমোদনের তালিকা খুলুন',
    ),
    'expenses_decided' => 
    array (
      'subject' => 'আপনার খরচের দাবি {{ number }}: {{ decision }}',
      'body' => '{{ organization }}-এ আপনার খরচের দাবি {{ number }} ({{ amount }}) {{ decision }}।

{{ note }}',
      'action' => 'আমার খরচ খুলুন',
    ),
    'expenses_paid' => 
    array (
      'subject' => 'আপনার খরচের দাবি {{ number }} পরিশোধিত',
      'body' => '{{ organization }}-এ আপনার খরচের দাবি {{ number }} ({{ amount }})-এর টাকা ফেরত দেওয়া হয়েছে।',
      'action' => 'আমার খরচ খুলুন',
    ),
  ),
  'catalog' => 
  array (
    'expenses_submitted' => 
    array (
      'name' => 'অনুমোদনের অপেক্ষায় খরচের দাবি',
      'description' => 'যাঁরা দাবির পরের ধাপের সিদ্ধান্ত দিতে পারেন তাঁদের।',
    ),
    'expenses_decided' => 
    array (
      'name' => 'খরচের দাবির সিদ্ধান্ত',
      'description' => 'কর্মীকে, তাঁর দাবি অনুমোদিত বা বাতিল হলে।',
    ),
    'expenses_paid' => 
    array (
      'name' => 'খরচের দাবি পরিশোধিত',
      'description' => 'কর্মীকে, তাঁর দাবির টাকা ফেরত দেওয়া হলে।',
    ),
  ),
);
