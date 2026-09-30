<?php

// Security alerts (Phase 9-2). "description" is for the platform operators;
// "notice" is what an organization or partner is told (no addresses or ids).

return [

    'severity' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],

    'platform' => [
        'subject' => '[:severity] :title (:count)',
        'seen' => 'Seen :count times, from :first to :last.',
        'next' => 'Check the security log, then acknowledge: php artisan security:alerts --ack=:id',
    ],

    'kinds' => [
        'brute_force_account' => [
            'title' => 'Many failed sign-ins to one account',
            'description' => 'Repeated wrong passwords or second steps for one account: someone may be guessing it. The person has been told.',
            'notice' => ':count failed sign-ins to one account.',
        ],
        'brute_force_address' => [
            'title' => 'Many failed sign-ins from one address',
            'description' => 'One network address tries many sign-ins: likely automated guessing across accounts. Consider blocking it at the firewall.',
            'notice' => ':count failed sign-ins from one address.',
        ],
        'access_denied_spike' => [
            'title' => 'Many refused requests',
            'description' => 'One person or address keeps asking for what they may not see or change: a broken client, or someone probing.',
            'notice' => ':count refused requests.',
        ],
        'rate_limited_spike' => [
            'title' => 'Many requests over the limit',
            'description' => 'One address keeps going over the request limits: a script or an attack.',
            'notice' => ':count requests over the limit.',
        ],
        'cross_tenant_attempt' => [
            'title' => 'Attempt to reach another organization',
            'description' => 'Someone asked for an organization or client that is not theirs (refused). Check who it was and why.',
            'notice' => 'Someone tried to reach data that is not theirs; it was refused.',
        ],
        'data_export' => [
            'title' => 'Data export requested',
            'description' => 'A full data export or an audit log export was requested.',
            'notice' => 'An export of your data or your audit log was requested. Make sure it was expected.',
        ],
        'module_switched_off' => [
            'title' => 'Module switched off or its data set for removal',
            'description' => 'A module was switched off, or its data was scheduled for removal.',
            'notice' => 'A module was switched off, or its data was scheduled for removal. The audit log shows who did it and why.',
        ],
        'sensitive_rule_changed' => [
            'title' => 'Sensitive setting changed',
            'description' => 'A setting that needs a second approver was changed.',
            'notice' => 'A sensitive setting was changed after approval. The audit log shows the old and new value.',
        ],
        'mfa_reset' => [
            'title' => 'Two-step sign-in reset',
            'description' => 'A member\'s second steps were cleared by an admin (approved by another).',
            'notice' => 'A member\'s two-step sign-in was reset. They set it up again at their next sign-in.',
        ],
        'partner_api_key_created' => [
            'title' => 'New API key',
            'description' => 'A partner created an API key for its own systems.',
            'notice' => 'A new API key was created for your account.',
        ],
        'restore_drill_failed' => [
            'title' => 'Restore drill failed',
            'description' => 'The monthly restore drill could not restore or check the newest backup. Backups may not be usable: look at the drill report next to the backup set.',
            'notice' => 'The backup check failed.',
        ],
    ],

];
