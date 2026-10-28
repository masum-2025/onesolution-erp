<?php

// Business connections (NET-1): errors, statuses, delivery failures.

return [
    'statuses' => [
        'invited' => 'Invited',
        'active' => 'Active',
        'paused' => 'Paused',
        'ended' => 'Ended',
    ],
    'errors' => [
        'not_found' => 'This connection was not found here. Open it again from your connections.',
        'not_during_support' => 'Business connections are not shown during support access.',
        'provider_must_be_company' => 'Only a company invites clients. Switch to your company and make the invitation there.',
        'client_must_be_group_or_company' => 'Accept the invitation at your group or company, not at a branch or department.',
        'code_not_found' => 'No invitation has this code. Check it with the company that gave it to you.',
        'code_closed' => 'This invitation can no longer be used: it was used, withdrawn or has expired. Ask for a new code.',
        'own_organization' => 'This invitation is from your own organization. A connection is between two different clients.',
        'other_partner' => 'Connections with companies of another provider are not allowed for one of you. Ask your provider.',
        'already_connected' => 'You are already connected with this company. Change the shared factories on that connection instead.',
        'units_needed' => 'Choose at least one company or branch the other side may deal with.',
        'unit_not_yours' => 'Choose only your own companies and branches that are open.',
        'not_your_side' => 'Only the other side can do this on this connection.',
        'wrong_status' => 'This connection is :status now, so this cannot be done. Reload to see it as it is.',
        'version_conflict' => 'Someone changed this connection meanwhile. Reload and try again.',
        'not_active' => 'This connection is not active: nothing can be sent until it is resumed.',
        'unit_not_covered' => 'This factory is not shared on the connection. Ask the client to share it first.',
        'kind_not_available' => 'Sending :kind is not available: the module that handles it is off on your side.',
        'payload_too_large' => 'What you are sending is too large. Send files as attachments instead.',
        'file_too_large' => 'A file is larger than :mb MB, the largest that can be sent.',
        'file_missing' => 'A file to send was not found. Upload it again.',
    ],
    'delivery_errors' => [
        'connection_closed' => 'the connection was paused or ended before it arrived',
        'kind_off' => 'the other side does not use the module that receives it',
        'file_damaged' => 'a file could not be copied unchanged',
        'delivery_failed' => 'the other side could not store it',
    ],
];
