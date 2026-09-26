<?php

return [

    // Default wording. Placeholders use {{ name }}; a partner may reword any of these.
    'templates' => [
        'support_requested' => [
            'subject' => '{{ partner }} asks to look inside {{ organization }}',
            'body' => "{{ staff }} from {{ partner }} asks for read-only access to {{ organization }} for {{ minutes }} minutes.\n\nReason: {{ reason }}\nSeverity: {{ severity }}\n\nThey can only view, never change anything, and every page they open is recorded in your audit log. Nobody gets in until you approve.",
            'sms' => '{{ product }}: {{ partner }} asks for {{ minutes }} min read-only access to {{ organization }}. Approve or reject: {{ link }}',
            'action' => 'Review the request',
        ],
        'support_decided' => [
            'subject' => 'Support access to {{ organization }}: {{ decision }}',
            'body' => "Your request for support access to {{ organization }} was {{ decision }}.\n\nTheir note: {{ reason }}\n\nIf it was approved, the {{ minutes }} minutes start now.",
            'sms' => '{{ product }}: support access to {{ organization }} {{ decision }}. {{ link }}',
            'action' => 'Open support access',
        ],
        'exports_ready' => [
            'subject' => 'Your data export from {{ organization }} is ready',
            'body' => "The export of all data of {{ organization }} is ready to download.\n\nThe file is kept until {{ expires }}; after that, prepare a new one.",
            'action' => 'Download the export',
        ],
        'billing_invoice_issued' => [
            'subject' => 'Invoice {{ number }} from {{ product }}',
            'body' => "A new invoice for {{ organization }} is ready.\n\nAmount: {{ amount }}\nDue: {{ due }}",
            'sms' => '{{ product }}: invoice {{ number }} for {{ amount }}, due {{ due }}. {{ link }}',
            'action' => 'View the invoice',
        ],
        'billing_partner_invoice_issued' => [
            'subject' => 'Invoice {{ number }} for your clients',
            'body' => "This month's invoice for {{ partner }} is ready.\n\nAmount: {{ amount }}\nDue: {{ due }}",
            'action' => 'View the invoice',
        ],
        'transfers_requested' => [
            'subject' => '{{ organization }} wants to move to you',
            'body' => "{{ organization }} asks to move its account to you.\n\nTheir reason: {{ reason }}\n\nAccept or reject the request in your console.",
            'action' => 'Review the request',
        ],
        'transfers_completed' => [
            'subject' => '{{ organization }} is now with {{ partner }}',
            'body' => "Your account {{ organization }} has moved to {{ partner }}, with all its data.\n\nPlease review and accept your new provider's terms.",
            'action' => 'See your provider',
        ],
        'transfers_client_left' => [
            'subject' => '{{ organization }} has moved to another provider',
            'body' => "{{ organization }} moved its account to another provider. Its past invoices stay with you.",
            'action' => 'Open your clients',
        ],
        'legal_updated' => [
            'subject' => 'New version: {{ document }}',
            'body' => "{{ product }} published a new version of the {{ document }} for {{ organization }}.\n\nWhat changed: {{ summary }}\n\nPlease read and accept it.",
            'action' => 'Read and accept',
        ],
        'members_invited' => [
            'subject' => 'You are invited to {{ organization }} on {{ product }}',
            'body' => "{{ inviter }} added you to {{ organization }}.\n\nSet your password to start. The link works for 3 days.",
            'action' => 'Set your password',
        ],
        'members_added' => [
            'subject' => 'You now have access to {{ organization }}',
            'body' => "{{ inviter }} gave you access to {{ organization }} on {{ product }}. Sign in with your usual email and password.",
            'action' => 'Sign in',
        ],
        'identity_password_changed' => [
            'subject' => 'Your {{ product }} password was changed',
            'body' => "The password of your {{ product }} account was changed on {{ time }}, and every other device was signed out.\n\nIf this was you, there is nothing to do. If it was not you, reset your password now and check your email and phone in My account.",
            'sms' => '{{ product }}: your password was changed ({{ time }}). Not you? Reset it now: {{ link }}',
            'action' => 'Reset my password',
        ],
        'identity_contact_changed' => [
            'subject' => 'The {{ kind }} of your {{ product }} account was changed',
            'body' => "The {{ kind }} of your {{ product }} account was changed on {{ time }}. Messages now go to the new one.\n\nIf this was you, there is nothing to do. If it was not you, reset your password now.",
            'sms' => '{{ product }}: the {{ kind }} of your account was changed ({{ time }}). Not you? {{ link }}',
            'action' => 'Reset my password',
        ],
        'identity_signup_attempt' => [
            'subject' => 'Someone tried to sign up with your address',
            'body' => "Someone tried to create a new {{ product }} account with this address, which already has one. No new account was made.\n\nIf it was you, sign in instead (or reset your password if you forgot it). If not, you can ignore this message.",
            'sms' => '{{ product }}: someone tried to sign up with your number, which already has an account. If it was you, sign in: {{ link }}',
            'action' => 'Sign in',
        ],
    ],

    'catalog' => [
        'support_requested' => ['name' => 'Support access requested', 'description' => 'To the client people who can approve support access.'],
        'support_decided' => ['name' => 'Support access decided', 'description' => 'To your staff member who asked, when the client approves or rejects.'],
        'exports_ready' => ['name' => 'Data export ready', 'description' => 'To the client person who asked for the export.'],
        'billing_invoice_issued' => ['name' => 'New invoice (client)', 'description' => 'To the client people who can see billing.'],
        'billing_partner_invoice_issued' => ['name' => 'New invoice (you)', 'description' => 'To your owners and billing staff. Sent by us, in our brand.'],
        'transfers_requested' => ['name' => 'Client wants to move to you', 'description' => 'To your owners, when a client uses your transfer code.'],
        'transfers_completed' => ['name' => 'Move completed', 'description' => 'To the client\'s account owners, from their new provider.'],
        'transfers_client_left' => ['name' => 'Client moved away', 'description' => 'To your owners, when a client moves to another provider.'],
        'legal_updated' => ['name' => 'New terms to accept', 'description' => 'To the client\'s account owners, when you publish new terms or a new DPA.'],
        'members_invited' => ['name' => 'Invitation to a new account', 'description' => 'To someone added who has no account yet: a link to set a password.'],
        'members_added' => ['name' => 'Access added', 'description' => 'To someone added who already has an account.'],
        'identity_password_changed' => ['name' => 'Password changed', 'description' => 'To the person, on every address. Fixed wording.'],
        'identity_contact_changed' => ['name' => 'Email or phone changed', 'description' => 'To the person\'s old address. Fixed wording.'],
        'identity_signup_attempt' => ['name' => 'Sign-up with a taken address', 'description' => 'To the owner of the address. Fixed wording.'],
    ],

    'placeholders' => [
        'product' => 'Your product name',
        'time' => 'Date and time',
        'kind' => 'email or phone',
        'organization' => 'The client organization',
        'partner' => 'Your company name',
        'staff' => 'Your staff member who asked',
        'severity' => 'How urgent',
        'minutes' => 'Minutes of access',
        'reason' => 'The reason given',
        'decision' => 'approved or rejected',
        'expires' => 'Date the file is deleted',
        'number' => 'Invoice number',
        'amount' => 'Invoice total',
        'due' => 'Due date',
        'link' => 'Link to the screen',
        'document' => 'The document\'s title',
        'summary' => 'What changed',
        'inviter' => 'Who added them',
    ],

    // Example values for previews.
    'samples' => [
        'organization' => 'Sunrise School',
        'partner' => 'Acme Solutions',
        'staff' => 'Rahim Uddin',
        'severity' => 'High',
        'minutes' => '60',
        'reason' => 'Ticket #1432: the fee report shows the wrong totals.',
        'decision' => 'approved',
        'expires' => '3 October 2026',
        'number' => 'INV-2026-000123',
        'amount' => 'BDT 7,000.00',
        'due' => '10 October 2026',
        'document' => 'Terms of service',
        'summary' => 'Clearer payment terms.',
        'inviter' => 'Head Teacher',
    ],

    'severity' => ['critical' => 'Critical', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'],
    'decisions' => ['approved' => 'approved', 'rejected' => 'rejected'],

    'mail' => [
        'help' => 'Questions? Write to :email.',
        'why' => 'You get this because you use :product.',
    ],

    'test' => [
        'subject' => 'Test email from :product',
        'body' => "This is a test email from :product.\n\nIf you can read this, your clients will get their emails the same way.",
        'sms' => ':product: this is a test message.',
    ],

    'errors' => [
        'unknown_notification' => 'This message does not exist.',
        'channel_not_supported' => 'This message is not sent that way.',
        'unknown_placeholders' => 'This message cannot use :list. Use only the placeholders listed.',
        'domain_exists' => 'You already have a sending domain, or another partner uses this one. Remove the old one first.',
        'custom_domain_not_allowed' => 'Your account cannot send from its own domain. Ask us to allow it.',
        'no_mail_domain' => 'Add a sending domain first.',
        'verification_failed' => 'These DNS records are missing or wrong: :checks. Check them with your DNS provider and try again; changes can take up to an hour.',
        'no_sms_sender' => 'You have no SMS sender ID.',
        'sms_disabled' => 'SMS is turned off for your account. Turn on "Send SMS" in your partner rules first.',
        'role_not_allowed' => 'Only the owner can change how messages are sent.',
    ],

    'validation' => [
        'domain' => 'Enter a domain such as mail.yourcompany.com, without http:// or an @.',
        'sender_id' => 'Use 3 to 11 letters, digits or spaces, with at least one letter.',
        'phone' => 'Enter the number in international format, e.g. +8801712345678.',
    ],

    'messages' => [
        'domain_added' => 'Domain added. Publish the DNS records below, then check them.',
        'domain_verified' => 'All records check out. Your emails now come from your domain.',
        'domain_removed' => 'Domain removed. Emails come from our address under your name again.',
        'sender_saved' => 'Sender saved.',
        'test_email_sent' => 'Test email sent to :to from :from.',
        'sender_id_requested' => 'Sender ID sent for approval. We register it with the operators; this can take a few days.',
        'sender_id_approved' => 'Sender ID saved and in use.',
        'sender_id_removed' => 'Sender ID removed.',
        'test_sms_sent' => 'Test SMS sent to :to as :sender.',
        'template_saved' => 'Message saved. It is used from the next message sent.',
        'template_reset' => 'Back to the default wording.',
    ],
];
