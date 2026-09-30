<?php

return [
    'retention_days' => [
        'label' => 'Keep audit entries for (days)',
        'description' => 'Older entries are removed every night. Empty keeps them for ever. At least 365 days; entries about money follow their own setting.',
    ],
    'money_retention_days' => [
        'label' => 'Keep money and pay entries for (days)',
        'description' => 'Entries about billing, payments and payroll. Empty keeps them for ever. At least 8 years (2922 days); check your country\'s accounting law.',
    ],
];
