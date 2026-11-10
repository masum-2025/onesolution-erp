<?php

/*
| Patient billing (php artisan make:erp-module database/module-definitions/patient_billing.php).
| Only the simple lists come from here (services and their prices, corporate and panel
| clients); bills, payments, advances, refunds, discount approval and the cash day are
| written by hand in the module.
*/

$label = fn (string $en, string $bn) => ['en' => $en, 'bn' => $bn];

return [
    'key' => 'patient_billing',
    'name' => $label('Patient billing', 'রোগীর বিল'),
    'description' => $label(
        'Bills for patients: services and prices, payments and advances, discounts with approval, refunds, corporate and panel clients, and closing the cash each day.',
        'রোগীর বিল: সেবা ও দাম, টাকা নেওয়া ও অগ্রিম, অনুমোদনসহ ছাড়, ফেরত, কর্পোরেট ও প্যানেল প্রতিষ্ঠান আর প্রতিদিন ক্যাশ বন্ধ।'
    ),
    'requires' => ['patients'],
    'icon' => 'receipt',
    'entities' => [
        'service' => [
            'label' => $label('Service', 'সেবা'),
            'plural' => $label('Services and prices', 'সেবার দাম'),
            'title' => 'name',
            'search' => ['code'],
            'filters' => ['category', 'is_active'],
            'columns' => ['code', 'name'],
            'unique' => ['code'],
            'fields' => [
                'code' => ['type' => 'text', 'required' => true, 'max' => 30, 'unique' => true, 'label' => $label('Code', 'কোড')],
                'name' => ['type' => 'translatable', 'required' => true, 'max' => 150, 'label' => $label('Service name', 'সেবার নাম')],
                'category' => ['type' => 'enum', 'required' => true, 'label' => $label('Type', 'ধরন'), 'options' => [
                    'consultation' => $label('Consultation', 'পরামর্শ'),
                    'test' => $label('Test', 'পরীক্ষা'),
                    'bed' => $label('Bed or cabin', 'বেড বা কেবিন'),
                    'procedure' => $label('Procedure', 'প্রসিডিউর'),
                    'other' => $label('Other', 'অন্যান্য'),
                ]],
                'price' => ['type' => 'money', 'required' => true, 'label' => $label('Price', 'দাম')],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
        'panel' => [
            'label' => $label('Corporate or panel client', 'কর্পোরেট বা প্যানেল'),
            'plural' => $label('Corporate and panel clients', 'কর্পোরেট ও প্যানেল'),
            'title' => 'name',
            'search' => ['name'],
            'filters' => ['kind', 'is_active'],
            'columns' => ['name'],
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max' => 150, 'label' => $label('Company name', 'প্রতিষ্ঠানের নাম')],
                'kind' => ['type' => 'enum', 'required' => true, 'label' => $label('Kind', 'ধরন'), 'options' => [
                    'corporate' => $label('Corporate', 'কর্পোরেট'),
                    'panel' => $label('Insurance or panel', 'বীমা বা প্যানেল'),
                ]],
                'credit_limit' => ['type' => 'money', 'label' => $label('Credit limit (empty = no limit)', 'বাকির সীমা (খালি = সীমা নেই)')],
                'discount_percent' => ['type' => 'integer', 'label' => $label('Discount (%)', 'ছাড় (%)')],
                'is_active' => ['type' => 'boolean', 'label' => $label('In use', 'চালু')],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'discount_approval_above_percent',
            'type' => 'integer',
            'default' => 10,
            'min' => 0,
            'max' => 100,
            'label' => $label('Discounts above this need approval (%)', 'এর বেশি ছাড়ে অনুমোদন লাগবে (%)'),
            'description' => $label('A larger discount on a bill waits for someone else to approve it.', 'বিলে এর চেয়ে বেশি ছাড় দিলে অন্য কেউ অনুমোদন না করা পর্যন্ত অপেক্ষা করে।'),
        ],
    ],
];
