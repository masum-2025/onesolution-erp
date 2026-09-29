<?php

return [
    'errors' => [
        'invalid_code' => 'That code is not right. Check the app, or use one of your recovery codes.',
        'challenge_ended' => 'Signing in took too long or had too many tries. Enter your password again.',
        'passkey_failed' => 'The passkey was not accepted. Try again, or use another way to sign in.',
        'passkey_unavailable' => 'Passkeys work only on a secure (https) address.',
        'already_enabled' => 'The authenticator app is already set up. Remove it first to set up a new one.',
        'not_started' => 'Start setting up the authenticator app first.',
        'still_required' => 'Your organization requires two-step sign-in. Add another way (app or passkey) before removing this one.',
        'step_up_required' => 'For this action, confirm it is you with your second step.',
        'reset_self' => 'You cannot reset your own two-step sign-in here. Use a recovery code instead.',
        'reset_same_person' => 'Another admin must approve this reset.',
        'reset_elsewhere' => 'This person also works for other organizations, so their sign-in cannot be reset from here. They can use a recovery code, or ask the service provider.',
        'reset_nothing' => 'This person has not set up two-step sign-in.',
        'reset_pending' => 'A reset for this person is already waiting for approval.',
        'reset_not_open' => 'This reset was already decided or has expired.',
    ],
    'messages' => [
        'totp_enabled' => 'The authenticator app is set up. Keep your recovery codes somewhere safe.',
        'totp_disabled' => 'The authenticator app was removed.',
        'codes_regenerated' => 'New recovery codes made. The old ones no longer work.',
        'passkey_added' => 'Passkey added.',
        'passkey_renamed' => 'Passkey renamed.',
        'passkey_removed' => 'Passkey removed.',
        'confirmed' => 'Confirmed.',
        'reset_requested' => 'Reset requested. Another admin must approve it.',
        'reset_approved' => 'Two-step sign-in was reset. The person sets it up again at their next sign-in.',
        'reset_rejected' => 'Reset rejected.',
    ],
    'methods' => [
        'totp' => 'Authenticator app',
        'recovery_code' => 'Recovery code',
        'passkey' => 'Passkey',
    ],
];
