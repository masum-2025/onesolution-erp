<?php

return [

    'errors' => [
        'kind_not_available' => 'This kind of record cannot be shared in the portal here. Turn on the portal and the module that holds these records.',
        'record_not_found' => 'This record does not exist here.',
        'relation_not_allowed' => 'This relation is not possible for this kind of record.',
        'bad_contact' => 'Enter a valid email or mobile number to send the invitation to.',
        'invitation_not_found' => 'This invitation code is not right. Check it and try again, or ask for a new one.',
        'invitation_closed' => 'This invitation was already used, cancelled or has expired. Ask for a new one.',
        'contact_mismatch_sms' => 'This invitation is for the mobile number :contact. Sign in with the account that has this number verified, or verify it on My account first.',
        'contact_mismatch_mail' => 'This invitation is for :contact. Sign in with the account that has this email verified, or verify it on My account first.',
        'account_exists' => 'There is already an account with this address. Sign in, then use the invitation.',
        'already_staff' => 'You already work here with your own access, so a portal link is not needed.',
        'too_many_links' => 'You are already linked to :max records here, the most allowed. Ask the organization if you need more.',
        'link_not_found' => 'This record is not available to you.',
        'link_not_pending' => 'This link was already decided.',
    ],

    'messages' => [
        'invited' => 'Invitation created. The code is shown only now: copy or print it if you hand it over yourself.',
        'invitation_revoked' => 'Invitation cancelled. The link and code no longer work.',
        'joined' => 'You are in. You can see the record now.',
        'joined_pending' => 'Thanks! The organization will confirm the link; you will see the record then.',
        'approved' => 'Approved. They can see the record now.',
        'rejected' => 'Rejected.',
        'revoked' => 'Access removed. They no longer see this record.',
    ],

    // Fixed wording (not partner-editable): it carries a one-time code.
    'invitation' => [
        'mail_subject' => ':organization invites you to its :product portal',
        'mail_body' => "Hello :name,\n\n:organization invites you to its portal, where you can see your records.\n\nOpen the link below, or enter the code :code at the portal's join page. It works until :expires.\n\nIf you do not know :organization, ignore this message.",
        'sms' => ':organization invites you to its portal. Code :code (until :expires): :link',
        'action' => 'Join the portal',
    ],

];
