<?php

return [
    'errors' => [
        'invalid_code' => 'This transfer code is not valid. It may be mistyped, expired or already used; ask the new provider for a new one.',
        'same_partner' => 'You are already with this provider.',
        'destination_inactive' => 'This provider cannot take new clients right now.',
        'already_open' => 'A move is already waiting. Cancel it before asking for another.',
        'not_open' => 'This move is no longer waiting for a decision.',
        'owners_only' => 'Only an owner of the whole account can move it to another provider.',
        'not_found' => 'This move does not exist.',
        'consent_required' => 'Confirm that you agree to the move.',
        'blocked' => 'The new provider cannot take this account: :problems',
        'role_not_allowed' => 'Only the owner can decide on moves.',
    ],

    'messages' => [
        'requested' => ':partner has been asked to accept your account. You will get an email when they decide.',
        'completed' => 'Your account is now with :partner.',
        'cancelled' => 'The move is cancelled.',
        'accepted' => ':client is now your client.',
        'rejected' => 'The move is rejected. The client has been told.',
        'code_created' => 'Transfer code created. Give it to the client now; it is shown only once.',
        'code_revoked' => 'Transfer code revoked.',
    ],
];
