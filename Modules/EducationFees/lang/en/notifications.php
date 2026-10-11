<?php

// Messages fees send (NotificationCatalog: module notifications).
return [
    'templates' => [
        'education_fees_due_soon' => [
            'subject' => 'Fee due on {{ due }} for {{ student }}',
            'body' => '{{ organization }}: bill {{ number }} of {{ student }} for {{ amount }} is due on {{ due }}. You can pay at the counter or online.',
            'sms' => '{{ organization }}: {{ student }}\'s fee {{ amount }} (bill {{ number }}) is due on {{ due }}.',
            'action' => 'See the fees',
        ],
        'education_fees_overdue' => [
            'subject' => 'Fee overdue for {{ student }}',
            'body' => '{{ organization }}: bill {{ number }} of {{ student }} for {{ amount }} was due on {{ due }} and is not paid yet. Please pay soon; a late fine may apply.',
            'sms' => '{{ organization }}: {{ student }}\'s fee {{ amount }} (bill {{ number }}) was due on {{ due }}. Please pay soon.',
            'action' => 'Pay the fees',
        ],
        'education_fees_dues_reminder' => [
            'subject' => '{{ student }} owes {{ amount }}',
            'body' => '{{ organization }}: {{ student }} owes {{ amount }} on {{ bills }} bill(s), the oldest due on {{ due }}. Please pay at the counter or online.',
            'sms' => '{{ organization }}: {{ student }} owes {{ amount }} ({{ bills }} bills). Please pay soon.',
            'action' => 'See the fees',
        ],
        'education_fees_payment_received' => [
            'subject' => 'Payment received for {{ student }}',
            'body' => '{{ organization }} received {{ amount }} for {{ student }}. Receipt {{ number }}. Thank you.',
            'sms' => '{{ organization }}: received {{ amount }} for {{ student }}, receipt {{ number }}. Thank you.',
            'action' => 'See the receipt',
        ],
    ],
    'catalog' => [
        'education_fees_due_soon' => [
            'name' => 'Fee due soon',
            'description' => 'To families some days before a fee bill is due (rule).',
        ],
        'education_fees_overdue' => [
            'name' => 'Fee overdue',
            'description' => 'To families some days after an unpaid bill was due (rule).',
        ],
        'education_fees_dues_reminder' => [
            'name' => 'Fee reminder from the office',
            'description' => 'To families when the office reminds them of what a student owes.',
        ],
        'education_fees_payment_received' => [
            'name' => 'Fee received',
            'description' => 'To families after a payment, when the institution asks for it.',
        ],
    ],
];
