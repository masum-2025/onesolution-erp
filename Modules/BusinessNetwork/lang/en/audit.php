<?php

// Audit log labels for "business_network.*" actions (AuditQuery::label).

return [
    'invited' => 'Connection invitation made',
    'accepted' => 'Connection accepted',
    'units_changed' => 'Shared factories changed',
    'paused' => 'Connection paused',
    'resumed' => 'Connection resumed',
    'ended' => 'Connection ended',
    'message_sent' => 'Sent over a connection',
    'message_received' => 'Received over a connection',
    'message_failed' => 'Not delivered over a connection',
];
