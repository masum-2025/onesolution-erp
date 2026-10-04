<?php

/*
| Chart of accounts template "general": a company's first chart, copied into
| its own accounts when Accounting is set up (rule accounting.chart_template
| picks the template). After that the accounts are the company's: renamed,
| added or archived freely. Sector templates extend this one.
|
| accounts: code, name (en, bn), type (top level only; children take their
|           group's type), group (a heading), parent (code of its group).
| postings: posting key (manifest ledger_accounts) => account code.
|
| Codes and names are a starting point to be reviewed by an accountant.
*/

return [
    'key' => 'general',
    'accounts' => [
        ['code' => '1000', 'name' => ['en' => 'Assets', 'bn' => 'সম্পদ'], 'type' => 'asset', 'group' => true],
        ['code' => '1100', 'name' => ['en' => 'Current assets', 'bn' => 'চলতি সম্পদ'], 'parent' => '1000', 'group' => true],
        ['code' => '1110', 'name' => ['en' => 'Cash in hand', 'bn' => 'হাতে নগদ'], 'parent' => '1100'],
        ['code' => '1120', 'name' => ['en' => 'Bank accounts', 'bn' => 'ব্যাংক হিসাব'], 'parent' => '1100'],
        ['code' => '1130', 'name' => ['en' => 'Mobile wallets', 'bn' => 'মোবাইল ওয়ালেট'], 'parent' => '1100'],
        ['code' => '1140', 'name' => ['en' => 'Accounts receivable', 'bn' => 'প্রাপ্য হিসাব'], 'parent' => '1100'],
        ['code' => '1150', 'name' => ['en' => 'Inventory', 'bn' => 'মজুদ পণ্য'], 'parent' => '1100'],
        ['code' => '1160', 'name' => ['en' => 'Advances and prepayments', 'bn' => 'অগ্রিম ও অগ্রিম পরিশোধ'], 'parent' => '1100'],
        ['code' => '1200', 'name' => ['en' => 'Fixed assets', 'bn' => 'স্থায়ী সম্পদ'], 'parent' => '1000', 'group' => true],
        ['code' => '1210', 'name' => ['en' => 'Furniture and fixtures', 'bn' => 'আসবাবপত্র ও ফিক্সচার'], 'parent' => '1200'],
        ['code' => '1220', 'name' => ['en' => 'Equipment and machinery', 'bn' => 'যন্ত্রপাতি'], 'parent' => '1200'],
        ['code' => '1230', 'name' => ['en' => 'Vehicles', 'bn' => 'যানবাহন'], 'parent' => '1200'],
        ['code' => '1290', 'name' => ['en' => 'Accumulated depreciation', 'bn' => 'পুঞ্জীভূত অবচয়'], 'parent' => '1200'],

        ['code' => '2000', 'name' => ['en' => 'Liabilities', 'bn' => 'দায়'], 'type' => 'liability', 'group' => true],
        ['code' => '2100', 'name' => ['en' => 'Current liabilities', 'bn' => 'চলতি দায়'], 'parent' => '2000', 'group' => true],
        ['code' => '2110', 'name' => ['en' => 'Accounts payable', 'bn' => 'প্রদেয় হিসাব'], 'parent' => '2100'],
        ['code' => '2120', 'name' => ['en' => 'Salaries payable', 'bn' => 'প্রদেয় বেতন'], 'parent' => '2100'],
        ['code' => '2130', 'name' => ['en' => 'Taxes payable', 'bn' => 'প্রদেয় কর'], 'parent' => '2100'],
        ['code' => '2140', 'name' => ['en' => 'Accrued expenses', 'bn' => 'বকেয়া খরচ'], 'parent' => '2100'],
        ['code' => '2200', 'name' => ['en' => 'Long-term liabilities', 'bn' => 'দীর্ঘমেয়াদি দায়'], 'parent' => '2000', 'group' => true],
        ['code' => '2210', 'name' => ['en' => 'Loans', 'bn' => 'ঋণ'], 'parent' => '2200'],

        ['code' => '3000', 'name' => ['en' => 'Equity', 'bn' => 'মালিকানা স্বত্ব'], 'type' => 'equity', 'group' => true],
        ['code' => '3100', 'name' => ['en' => "Owner's capital", 'bn' => 'মালিকের মূলধন'], 'parent' => '3000'],
        ['code' => '3200', 'name' => ['en' => 'Retained earnings', 'bn' => 'সংরক্ষিত মুনাফা'], 'parent' => '3000'],
        ['code' => '3300', 'name' => ['en' => 'Opening balance adjustments', 'bn' => 'প্রারম্ভিক জের সমন্বয়'], 'parent' => '3000'],
        ['code' => '3400', 'name' => ['en' => 'Drawings', 'bn' => 'উত্তোলন'], 'parent' => '3000'],

        ['code' => '4000', 'name' => ['en' => 'Income', 'bn' => 'আয়'], 'type' => 'income', 'group' => true],
        ['code' => '4100', 'name' => ['en' => 'Sales', 'bn' => 'বিক্রয়'], 'parent' => '4000'],
        ['code' => '4200', 'name' => ['en' => 'Service income', 'bn' => 'সেবা থেকে আয়'], 'parent' => '4000'],
        ['code' => '4900', 'name' => ['en' => 'Other income', 'bn' => 'অন্যান্য আয়'], 'parent' => '4000'],

        ['code' => '5000', 'name' => ['en' => 'Expenses', 'bn' => 'ব্যয়'], 'type' => 'expense', 'group' => true],
        ['code' => '5100', 'name' => ['en' => 'Cost of goods sold', 'bn' => 'বিক্রীত পণ্যের ব্যয়'], 'parent' => '5000'],
        ['code' => '5200', 'name' => ['en' => 'Salaries and wages', 'bn' => 'বেতন ও মজুরি'], 'parent' => '5000'],
        ['code' => '5300', 'name' => ['en' => 'Rent', 'bn' => 'ভাড়া'], 'parent' => '5000'],
        ['code' => '5400', 'name' => ['en' => 'Utilities', 'bn' => 'বিদ্যুৎ, পানি ও গ্যাস'], 'parent' => '5000'],
        ['code' => '5500', 'name' => ['en' => 'Office supplies', 'bn' => 'অফিস সামগ্রী'], 'parent' => '5000'],
        ['code' => '5600', 'name' => ['en' => 'Travel and transport', 'bn' => 'যাতায়াত ও পরিবহন'], 'parent' => '5000'],
        ['code' => '5700', 'name' => ['en' => 'Depreciation', 'bn' => 'অবচয়'], 'parent' => '5000'],
        ['code' => '5800', 'name' => ['en' => 'Bank charges', 'bn' => 'ব্যাংক চার্জ'], 'parent' => '5000'],
        ['code' => '5900', 'name' => ['en' => 'Other expenses', 'bn' => 'অন্যান্য ব্যয়'], 'parent' => '5000'],
    ],
    'postings' => [
        'accounting.retained_earnings' => '3200',
        'accounting.opening_balance' => '3300',
        'accounting.receivable' => '1140',
        'accounting.payable' => '2110',
        'accounting.online_collections' => '1130',
    ],
];
