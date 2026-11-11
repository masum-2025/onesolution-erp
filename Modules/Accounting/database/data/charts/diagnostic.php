<?php

/*
| Chart of accounts template "diagnostic": the general chart plus test
| income, patients' advances, panel (corporate and insurance) receivables
| and referral commission. Names and codes are a starting point for an
| accountant.
*/

return [
    'key' => 'diagnostic',
    'extends' => 'general',
    'accounts' => [
        ['code' => '1145', 'name' => ['en' => 'Patients receivable', 'bn' => 'রোগীর কাছে পাওনা'], 'parent' => '1100'],
        ['code' => '1146', 'name' => ['en' => 'Panel receivable (corporate and insurance)', 'bn' => 'প্যানেলের কাছে পাওনা (প্রতিষ্ঠান ও বীমা)'], 'parent' => '1100'],
        ['code' => '2155', 'name' => ['en' => 'Patients\' advances', 'bn' => 'রোগীর অগ্রিম'], 'parent' => '2100'],
        ['code' => '2145', 'name' => ['en' => 'Referral commission payable', 'bn' => 'রেফারেল কমিশন দেনা'], 'parent' => '2100'],
        ['code' => '4210', 'name' => ['en' => 'Test and investigation income', 'bn' => 'পরীক্ষা-নিরীক্ষা থেকে আয়'], 'parent' => '4000'],
        ['code' => '5180', 'name' => ['en' => 'Referral commission', 'bn' => 'রেফারেল কমিশন'], 'parent' => '5000'],
        ['code' => '5190', 'name' => ['en' => 'Reagents and lab consumables', 'bn' => 'রিএজেন্ট ও ল্যাবের সামগ্রী'], 'parent' => '5000'],
    ],
    // A diagnostic centre sells no goods.
    'remove' => ['4100', '5100'],
    'postings' => [
        'patient_billing.receivable' => '1145', 'patient_billing.panel_receivable' => '1146', 'patient_billing.advance' => '2155',
        'patient_billing.income' => '4210',
        'diagnostics.commission_expense' => '5180', 'diagnostics.commission_payable' => '2145',
        'crm.sales' => '4210', 'projects.services' => '4210', 'inventory.cogs' => '5190',
        'pos.sales' => '4210', 'online_orders.sales' => '4210',
    ],
];
