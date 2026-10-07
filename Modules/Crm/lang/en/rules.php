<?php

// CRM rule labels.
return [
    'duplicate_match' => [
        'label' => 'A contact is the same when',
        'description' => 'phone: the mobile number matches; phone_email: the number or the email matches.',
    ],
    'marketing_consent_required' => [
        'label' => 'Marketing needs consent',
        'description' => 'Offers by SMS or email only to contacts who agreed (the time is kept).',
    ],
    'follow_up_reminder_minutes' => [
        'label' => 'Remind before a follow-up (minutes)',
        'description' => 'The person a follow-up is given to is reminded this long before it.',
    ],
    'loyalty_points_per_100' => [
        'label' => 'Loyalty points per 100 spent',
        'description' => 'Points a customer earns at the counter for every 100 of the currency. 0 = no points.',
    ],
    'quote_valid_days' => [
        'label' => 'Quotes valid for (days)',
        'description' => 'From the issue day, unless a quote says otherwise.',
    ],
    'quote_prices_include_tax' => [
        'label' => 'Quote prices include VAT',
        'description' => 'Prices typed on estimates and quotations already include VAT; otherwise it is added.',
    ],
    'number_prefixes' => [
        'label' => 'Estimate and quotation number prefixes',
        'description' => 'Before the year and number, e.g. QT-2026-00001.',
    ],
];
