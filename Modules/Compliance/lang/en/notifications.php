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
        'compliance_result_received' => [
            'subject' => 'Audit result from {{ other }}: {{ service }} at {{ organization }}',
            'body' => '{{ other }} sent the result of {{ service }} for {{ organization }}. Check it and accept it into the register, or reject it with a reason.',
            'action' => 'Check the result',
        ],
        'compliance_renewal_answered' => [
            'subject' => '{{ other }} answered your renewal request: {{ status }}',
            'body' => 'Renewal of {{ service }} at {{ organization }}: {{ status }}. Planned day: {{ planned }}.',
            'action' => 'Open renewals',
        ],
    ],
    'catalog' => [
        'compliance_expiring' => [
            'name' => 'Audits to renew',
            'description' => 'To the people who manage compliance, at the reminder days before an audit expires and once it has expired.',
        ],
        'compliance_result_received' => [
            'name' => 'Audit result from a provider',
            'description' => 'To the people who accept results into the register, when a connected provider sends one.',
        ],
        'compliance_renewal_answered' => [
            'name' => 'Answer to a renewal request',
            'description' => 'To the people who send renewal requests, when the provider accepts, declines or finishes one.',
        ],
    ],
    'expires_on' => 'expires :date',
    'expired_on' => 'expired :date',
];
