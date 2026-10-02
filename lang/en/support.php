<?php

return [

    'errors' => [
        'grant_not_found' => 'Support request not found.',
        'already_open' => 'You already have an open request or active access for this client. Use it, or end it first.',
        'too_long' => 'This client allows support access for at most :max minutes. Ask for a shorter time.',
        'not_pending' => 'This request has already been answered.',
        'not_active' => 'This access has already ended.',
        'role_not_allowed' => 'Only partner owners and support staff can ask for support access.',
        'browser_only' => 'Support access works in the browser app only, not with API tokens.',
    ],

    'messages' => [
        'requested' => 'Request sent. The client decides; you will see the answer here.',
        'auto_approved' => 'The client lets this kind of request in at once. You can enter now.',
        'approved' => 'Support access approved. It ends by itself when the time is up.',
        'rejected' => 'Support request rejected.',
        'revoked' => 'Support access ended.',
    ],

    // Header bell.
    'attention' => [
        'requests' => 'Support access requests',
    ],
];
