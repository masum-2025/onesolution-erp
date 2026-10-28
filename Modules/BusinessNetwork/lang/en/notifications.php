<?php

return [
    'templates' => [
        'business_network_connected' => [
            'subject' => '{{ other }} accepted your connection',
            'body' => '{{ other }} accepted the business connection with {{ organization }}. You can now work together on the factories they shared.',
            'action' => 'Open connections',
        ],
        'business_network_paused' => [
            'subject' => '{{ other }} paused your connection',
            'body' => '{{ other }} paused the business connection with {{ organization }}. Nothing can be sent either way until they resume it.',
            'action' => 'Open connections',
        ],
        'business_network_ended' => [
            'subject' => '{{ other }} ended your connection',
            'body' => '{{ other }} ended the business connection with {{ organization }}. What you received stays yours; nothing more can be sent.

{{ reason }}',
            'action' => 'Open connections',
        ],
        'business_network_message_failed' => [
            'subject' => 'Something you sent to {{ other }} was not delivered',
            'body' => 'Something {{ organization }} sent to {{ other }} could not be delivered: {{ reason }}

Open connections to see it and send it again if needed.',
            'action' => 'Open connections',
        ],
    ],
    'catalog' => [
        'business_network_connected' => [
            'name' => 'Connection accepted',
            'description' => 'To the people who manage connections, when a client accepts an invitation.',
        ],
        'business_network_paused' => [
            'name' => 'Connection paused',
            'description' => 'To the people who manage connections, when the other side pauses one.',
        ],
        'business_network_ended' => [
            'name' => 'Connection ended',
            'description' => 'To the people who manage connections, when the other side ends one.',
        ],
        'business_network_message_failed' => [
            'name' => 'Not delivered',
            'description' => 'To the people who manage connections, when something sent to the other side could not be delivered.',
        ],
    ],
];
