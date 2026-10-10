<?php

// Rule labels (manifest "rules"), key = part after "education_fees.".
return [
    'due_day' => [
        'label' => 'Monthly fees due on day',
        'description' => 'The day of the month a monthly bill is due when the run names no date (the last day in shorter months).',
    ],
    'bill_number_format' => [
        'label' => 'Bill number format',
        'description' => 'Placeholders: {YYYY} {YY} {SEQ:n}. Counted per year.',
    ],
    'auto_monthly_billing' => [
        'label' => 'Bill each month automatically',
        'description' => 'On: every campus\'s monthly fees are billed and made final on this day without the office. Off: the office bills each month.',
    ],
    'bill_on_admission' => [
        'label' => 'Bill admission fees on admitting',
        'description' => 'A newly admitted student is billed the "on admission" heads at once.',
    ],
    'fees_include_tax' => [
        'label' => 'Fees include tax',
        'description' => 'Only for heads with a tax code. On: the fee already contains the tax. Off: the tax is added on top.',
    ],
    'sibling_discount_percent' => [
        'label' => 'Sibling discount (%)',
        'description' => 'Off heads that allow it, for the second and later brothers and sisters studying here. 0: none.',
    ],
    'concession_approval_above_percent' => [
        'label' => 'Discounts needing approval above (%)',
        'description' => 'A higher discount, or any fixed amount, waits for another person to approve it. Empty: never; 0: always.',
    ],
    'late_fine' => [
        'label' => 'Late fine',
        'description' => 'Off by default. Once, per day or per month late after the grace days, up to the most (0: no most). Amounts in the smallest unit (paisa).',
    ],
    'categories' => [
        'billing' => 'Billing',
        'discounts' => 'Discounts',
        'fines' => 'Late fines',
    ],
];
