<?php

// Meta (Facebook): partners' own apps, the Graph API, Meta's callbacks (SC-1).
return [
    'errors' => [
        'token_invalid' => 'Facebook no longer accepts this sign-in. Connect the page again. :detail',
        'permission_missing' => 'Facebook did not give the permission this needs. Connect the page again and allow every permission asked for. :detail',
        'rate_limited' => 'Facebook asked us to slow down. Try again in a few minutes. :detail',
        'unreachable' => 'Facebook cannot be reached right now. Try again in a few minutes.',
        'rejected' => 'Facebook refused this. :detail',
        'no_app' => 'Facebook is not set up for your account yet. Ask your provider to add their Meta app.',
        'app_check_failed' => 'Meta did not accept this app id and secret together. Copy both again from developers.facebook.com (App settings → Basic).',
        'version_conflict' => 'Someone changed this in the meantime. Reload the page and try again.',
        'nothing_pending' => 'There is no change waiting for approval.',
        'own_change' => 'You asked for this change, so another partner owner has to approve it.',
        'bad_signed_request' => 'The request was not signed by Meta.',
    ],
    'validation' => [
        'app_id' => 'The app id is a number of 6 to 30 digits (App settings → Basic).',
        'app_secret' => 'The app secret is 16 to 64 letters and digits (App settings → Basic → Show).',
    ],
    'messages' => [
        'needs_approval' => 'Saved. Another partner owner has to approve it before it is used.',
        'waits' => 'Saved. With no second owner to approve it, it is used once the waiting time is over.',
        'approved' => 'Approved. Your clients now connect through this app.',
        'rejected' => 'The change was dropped.',
    ],
    'deletion_done' => 'Your Facebook data held through this app has been deleted.',
];
