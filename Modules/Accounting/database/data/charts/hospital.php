<?php

/*
| Chart of accounts template "hospital": the general chart plus patient
| service income (one account for now: bills post as one income line),
| medicine sales, patients' advances, panel receivables, referral commission
| and medical supplies. The department income accounts are there for an
| accountant who moves amounts by journal until billing posts them apart.
*/

return [
    'key' => 'hospital',
    'extends' => 'general',
    'accounts' => [
        ['code' => '1145', 'name' => ['en' => 'Patients receivable', 'bn' => 'রোগীর কাছে পাওনা'], 'parent' => '1100'],
        ['code' => '1146', 'name' => ['en' => 'Panel receivable (corporate and insurance)', 'bn' => 'প্যানেলের কাছে পাওনা (প্রতিষ্ঠান ও বীমা)'], 'parent' => '1100'],
        ['code' => '2155', 'name' => ['en' => 'Patients\' advances', 'bn' => 'রোগীর অগ্রিম'], 'parent' => '2100'],
        ['code' => '2145', 'name' => ['en' => 'Referral commission payable', 'bn' => 'রেফারেল কমিশন দেনা'], 'parent' => '2100'],
        ['code' => '4130', 'name' => ['en' => 'Medicine sales', 'bn' => 'ওষুধ বিক্রি'], 'parent' => '4000'],
        ['code' => '4205', 'name' => ['en' => 'Patient service income', 'bn' => 'রোগীসেবা থেকে আয়'], 'parent' => '4000'],
        ['code' => '4210', 'name' => ['en' => 'Test and investigation income', 'bn' => 'পরীক্ষা-নিরীক্ষা থেকে আয়'], 'parent' => '4000'],
        ['code' => '4220', 'name' => ['en' => 'Consultation fees', 'bn' => 'ডাক্তারের ফি'], 'parent' => '4000'],
        ['code' => '4230', 'name' => ['en' => 'Bed and ward charges', 'bn' => 'বেড ও ওয়ার্ড ভাড়া'], 'parent' => '4000'],
        ['code' => '5170', 'name' => ['en' => 'Expired and damaged medicines', 'bn' => 'মেয়াদোত্তীর্ণ ও নষ্ট ওষুধ'], 'parent' => '5000'],
        ['code' => '5180', 'name' => ['en' => 'Referral commission', 'bn' => 'রেফারেল কমিশন'], 'parent' => '5000'],
        ['code' => '5190', 'name' => ['en' => 'Medical and surgical supplies', 'bn' => 'চিকিৎসা ও অস্ত্রোপচারের সামগ্রী'], 'parent' => '5000'],
    ],
    'postings' => [
        'patient_billing.receivable' => '1145', 'patient_billing.panel_receivable' => '1146', 'patient_billing.advance' => '2155',
        'patient_billing.income' => '4205',
        'diagnostics.commission_expense' => '5180', 'diagnostics.commission_payable' => '2145',
        'pos.sales' => '4130', 'inventory.adjustment' => '5170',
    ],
];
