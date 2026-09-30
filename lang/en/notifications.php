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
        'billing_payment_received' => [
            'subject' => 'Payment received: {{ amount }}',
            'body' => "Thank you. We received {{ amount }} for {{ organization }} ({{ method }}).\n\nYour receipt is invoice {{ number }}, marked paid.",
            'sms' => '{{ product }}: payment of {{ amount }} received, thank you. Receipt {{ number }}. {{ link }}',
            'action' => 'View the receipt',
        ],
        'billing_payment_failed' => [
            'subject' => 'Your payment of {{ amount }} did not go through',
            'body' => "The payment of {{ amount }} for {{ organization }} was not completed, and no money was taken by us.\n\nYou can try again, with the same or another method.",
            'action' => 'Try again',
        ],
        'billing_trial_ending' => [
            'subject' => 'Your {{ plan }} trial ends on {{ ends }}',
            'body' => "The free trial of {{ plan }} for {{ organization }} ends on {{ ends }}.\n\nTo keep its features, choose a plan before then. If you do not, you go back to the free plan and keep all your data.",
            'sms' => '{{ product }}: your {{ plan }} trial ends {{ ends }}. Keep it: {{ link }}',
            'action' => 'Choose a plan',
        ],
        'billing_trial_ended' => [
            'subject' => 'Your {{ plan }} trial has ended',
            'body' => "The free trial of {{ plan }} for {{ organization }} has ended, and you are back on the free plan. All your data is kept.\n\nYou can buy {{ plan }} at any time.",
            'action' => 'See plans',
        ],
        'billing_payment_overdue' => [
            'subject' => 'Invoice {{ number }} is overdue',
            'body' => "Invoice {{ number }} for {{ organization }} ({{ amount }}) was due on {{ due }}.\n\nPlease pay it by {{ read_only_on }}. After that the workspace becomes read-only until it is paid; your data stays safe. You can also move to the free plan instead.",
            'sms' => '{{ product }}: invoice {{ number }} ({{ amount }}) is overdue. Pay by {{ read_only_on }} to keep full access: {{ link }}',
            'action' => 'Pay now',
        ],
        'billing_workspace_restricted' => [
            'subject' => '{{ organization }} is now read-only',
            'body' => "Because a bill is still unpaid, {{ organization }} is now read-only. You can view and export everything, but not change it.\n\nPay the bill, or move to the free plan, and it works again at once. Nothing has been deleted.",
            'sms' => '{{ product }}: {{ organization }} is read-only until the bill is paid. Nothing is deleted. {{ link }}',
            'action' => 'Pay now',
        ],
        'billing_workspace_restored' => [
            'subject' => '{{ organization }} works normally again',
            'body' => 'Thank you. {{ organization }} is no longer read-only; everything works as before.',
            'action' => 'Open billing',
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
            'body' => '{{ organization }} moved its account to another provider. Its past invoices stay with you.',
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
            'body' => '{{ inviter }} gave you access to {{ organization }} on {{ product }}. Sign in with your usual email and password.',
            'action' => 'Sign in',
        ],
        'identity_deletion_requested' => [
            'subject' => 'Your {{ product }} account will be deleted on {{ date }}',
            'body' => "You asked us to delete your {{ product }} account. On {{ date }} your personal data will be erased and your own workspace closed.\n\nChanged your mind? Sign in and cancel it on My account before then. If you did not ask for this, sign in now, cancel it and change your password.",
            'sms' => '{{ product }}: your account will be deleted on {{ date }}. Not you, or changed your mind? Cancel: {{ link }}',
            'action' => 'Open My account',
        ],
        'identity_deletion_cancelled' => [
            'subject' => 'Your {{ product }} account will not be deleted',
            'body' => 'The deletion of your {{ product }} account was cancelled. Everything stays as it was. If this was not you, change your password.',
            'sms' => '{{ product }}: the deletion of your account was cancelled. Not you? {{ link }}',
            'action' => 'Open My account',
        ],
        'security_sign_in_attempts' => [
            'subject' => 'Someone is trying to sign in to your {{ product }} account',
            'body' => 'There were {{ count }} failed attempts to sign in to your {{ product }} account, starting {{ time }}.

If this was you, there is nothing to do. If it was not you, your password may be known: change it now, and turn on two-step sign-in in My account > Security.',
            'sms' => '{{ product }}: {{ count }} failed sign-ins to your account since {{ time }}. Not you? Change your password: {{ link }}',
            'action' => 'Open My account',
        ],
        'security_alert' => [
            'subject' => 'Security notice for {{ organization }}: {{ event }}',
            'body' => '{{ details }}

First seen: {{ time }}.

The audit log shows who did what and when. If you do not recognise this, contact your service provider at once.',
            'sms' => '{{ product }}: security notice for {{ organization }}: {{ event }}. {{ link }}',
            'action' => 'Open the audit log',
        ],
        'security_partner_alert' => [
            'subject' => 'Security notice for {{ partner }}: {{ event }}',
            'body' => '{{ details }}

First seen: {{ time }}.

If you do not recognise this, revoke the key and tell us at once.',
            'sms' => '{{ product }}: security notice for {{ partner }}: {{ event }}. {{ link }}',
            'action' => 'Open API keys',
        ],

        'partners_company_added' => [
            'subject' => '{{ company }} was added to {{ organization }}',
            'body' => "{{ partner }} added the company {{ company }} to {{ organization }}. It is part of your account and is billed with it.\n\nYou can add its people, branches and settings now.",
            'action' => 'Open organizations',
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
        'payments_merchant_change_requested' => [
            'subject' => 'Payment account change for {{ organization }}: please check',
            'body' => "{{ person }} asked to connect or change the {{ gateway }} account where {{ organization }}'s customers pay. It takes effect {{ takes_effect }}.

If you expected this, approve it (or let it wait). If not, reject it now and change that person's password: this decides where your customers' money goes.",
            'sms' => '{{ product }}: {{ person }} asked to change where {{ organization }} receives payments ({{ gateway }}). Not expected? Reject it now: {{ link }}',
            'action' => 'Review the change',
        ],
        'payments_merchant_change_applied' => [
            'subject' => 'Payment account for {{ organization }} changed',
            'body' => "The {{ gateway }} account change for {{ organization }} is now in effect ({{ person }}). Customers' online payments now go to it.

If this is not right, turn the account off at once and contact your gateway.",
            'action' => 'Open online payments',
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
        'billing_payment_received' => ['name' => 'Payment received', 'description' => 'To the client, when an online payment succeeds: the receipt.'],
        'billing_payment_failed' => ['name' => 'Payment failed', 'description' => 'To the client, when an online payment fails or is cancelled.'],
        'billing_trial_ending' => ['name' => 'Trial ends soon', 'description' => 'To a self-serve client a few days before its free trial ends.'],
        'billing_trial_ended' => ['name' => 'Trial ended', 'description' => 'To a self-serve client whose trial ended without a purchase.'],
        'billing_payment_overdue' => ['name' => 'Payment overdue', 'description' => 'Reminders to a self-serve client with an unpaid invoice.'],
        'billing_workspace_restricted' => ['name' => 'Read-only for an unpaid bill', 'description' => 'To a self-serve client whose grace period ended.'],
        'billing_workspace_restored' => ['name' => 'Working again', 'description' => 'To a self-serve client once the bill is settled.'],
        'transfers_requested' => ['name' => 'Client wants to move to you', 'description' => 'To your owners, when a client uses your transfer code.'],
        'transfers_completed' => ['name' => 'Move completed', 'description' => 'To the client\'s account owners, from their new provider.'],
        'transfers_client_left' => ['name' => 'Client moved away', 'description' => 'To your owners, when a client moves to another provider.'],
        'legal_updated' => ['name' => 'New terms to accept', 'description' => 'To the client\'s account owners, when you publish new terms or a new DPA.'],
        'members_invited' => ['name' => 'Invitation to a new account', 'description' => 'To someone added who has no account yet: a link to set a password.'],
        'members_added' => ['name' => 'Access added', 'description' => 'To someone added who already has an account.'],
        'partners_company_added' => ['name' => 'Company added to a group', 'description' => 'To the group\'s owners, when you add a company to their group.'],
        'identity_password_changed' => ['name' => 'Password changed', 'description' => 'To the person, on every address. Fixed wording.'],
        'identity_contact_changed' => ['name' => 'Email or phone changed', 'description' => 'To the person\'s old address. Fixed wording.'],
        'payments_merchant_change_requested' => ['name' => 'Payment account change asked', 'description' => 'To everyone who may approve it, at once. Fixed wording.'],
        'payments_merchant_change_applied' => ['name' => 'Payment account changed', 'description' => 'To everyone who may manage payment accounts. Fixed wording.'],
        'identity_signup_attempt' => ['name' => 'Sign-up with a taken address', 'description' => 'To the owner of the address. Fixed wording.'],
        'identity_deletion_requested' => ['name' => 'Account deletion asked', 'description' => 'To the person, on every address, with the date. Fixed wording.'],
        'identity_deletion_cancelled' => ['name' => 'Account deletion cancelled', 'description' => 'To the person, on every address. Fixed wording.'],
        'security_sign_in_attempts' => ['name' => 'Failed sign-in attempts', 'description' => 'To the person, on every address, when many sign-ins to their account fail. Fixed wording.'],
        'security_alert' => ['name' => 'Security notice', 'description' => 'To the people who read the client\x27s audit log: exports, modules switched off, sensitive settings, sign-in resets. Fixed wording.'],
        'security_partner_alert' => ['name' => 'Security notice to you', 'description' => 'To your owners, e.g. when an API key is created. Fixed wording.'],
    ],

    'placeholders' => [
        'event' => 'What happened',
        'details' => 'More about it',
        'count' => 'How many times',
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
        'method' => 'How it was paid',
        'plan' => 'Plan name',
        'ends' => 'Date the trial ends',
        'read_only_on' => 'Date it becomes read-only',
        'date' => 'Date of the deletion',
        'gateway' => 'Payment gateway',
        'person' => 'Who made the change',
        'takes_effect' => 'When it takes effect',
        'company' => 'The new company',
    ],

    // Example values for previews.
    'samples' => [
        'event' => 'Data export requested',
        'details' => 'A full export of your data was requested.',
        'count' => '12',
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
        'method' => 'bKash',
        'plan' => 'Personal Plus',
        'ends' => '11 October 2026',
        'read_only_on' => '17 October 2026',
        'date' => '27 October 2026',
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
