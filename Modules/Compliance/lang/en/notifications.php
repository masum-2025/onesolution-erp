<?php

return [
    'templates' => [
        'compliance_expiring' => [
            'subject' => '{{ count }} factory audit(s) of {{ organization }} to renew',
            'body' => 'These audits of {{ organization }} expire soon or have expired:

{{ audits }}

Open the register to plan the renewals.',
            'action' => 'Open the audit register',
        ],
    ],
    'catalog' => [
        'compliance_expiring' => [
            'name' => 'Audits to renew',
            'description' => 'To the people who manage compliance, at the reminder days before an audit expires and once it has expired.',
        ],
    ],
    'expires_on' => 'expires :date',
    'expired_on' => 'expired :date',
];
