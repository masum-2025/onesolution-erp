<?php

return [
    'errors' => [
        'invalid_key' => 'This API key is not valid. It may be mistyped, revoked or expired.',
        'key_inactive' => 'This API key cannot be used right now.',
        'missing_scope' => 'This API key may not do this. It needs the ":scope" scope.',
        'idempotency_mismatch' => 'This Idempotency-Key was already used for a different request.',
        'role_not_allowed' => 'Only the owner can manage API keys.',
        'not_found' => 'Not found.',
    ],

    'messages' => [
        'key_created' => 'API key created. Copy it now; it is shown only once.',
        'key_revoked' => 'API key revoked. Systems using it stop working at once.',
    ],
];
