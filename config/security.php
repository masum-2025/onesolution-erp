<?php

/*
|--------------------------------------------------------------------------
| Platform hardening (Phase 8-2)
|--------------------------------------------------------------------------
|
| Infrastructure settings only: they apply before any tenant is known, so
| they are not rules. See docs/security-checklist.md for what each covers.
|
*/

return [

    // Strict-Transport-Security, sent only on HTTPS responses (also for partner domains).
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        // Partner domains are separate hosts, so subdomains stay opt-in.
        'include_subdomains' => (bool) env('SECURITY_HSTS_SUBDOMAINS', false),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    // The general limit on every /api request, on top of the stricter per-action limits.
    // Requests per minute; placeholders until real traffic is measured.
    'api' => [
        'per_user' => (int) env('SECURITY_API_PER_USER', 120),
        'per_organization' => (int) env('SECURITY_API_PER_ORGANIZATION', 600),
        'per_ip' => (int) env('SECURITY_API_PER_IP', 300),
        // Largest page any list endpoint returns.
        'max_per_page' => 100,
    ],

    // Security events (failed sign-ins, refused access, sensitive changes) go to this
    // log channel as JSON lines, for the log collector. Alerts come in Phase 9.
    'log_channel' => env('SECURITY_LOG_CHANNEL', 'security'),

    // Audit actions that are also written to the security log.
    'logged_actions' => [
        'auth.*', 'identity.password_*', 'identity.totp_*', 'identity.passkey_*',
        'identity.recovery*', 'identity.mfa_reset_*', 'identity.sessions_ended',
        'membership.roles_changed', 'role.*', 'rule.*', 'module.*', 'partner.module_changed',
        'partner.api_key_*', 'partner.domain_*', 'data.export_*', 'identity.data_downloaded',
        'support.requested', 'support.approved', 'support.auto_approved', 'support.session_started',
        'client.transfer*', 'payments.merchant_account.*', 'offline.device_revoked',
        'offline.operation_quarantined', 'organization.moved', 'backup.*',
    ],

    'backups' => [
        // Where backup sets are written. Point this at an off-site disk with object lock
        // (write once) in production; sets are never overwritten, only pruned by age.
        'disk' => env('BACKUP_DISK', 'backups'),
        // How many daily sets to keep.
        'keep' => (int) env('BACKUP_KEEP', 14),
        // Encryption keys (base64 of 32 random bytes) by version. The current version
        // encrypts; older versions stay listed only to read older sets.
        'key_version' => env('BACKUP_KEY_VERSION', 'v1'),
        'keys' => array_filter([
            'v1' => env('BACKUP_KEY_V1'),
            'v2' => env('BACKUP_KEY_V2'),
            'v3' => env('BACKUP_KEY_V3'),
        ]),
        // Folders of the private file disk that are copied (exports are temporary).
        'files' => [
            'disk' => 'local',
            'exclude' => ['exports'],
        ],
        // The database tools, when they are not on PATH.
        'binaries' => [
            'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
            'mysql' => env('BACKUP_MYSQL', 'mysql'),
            'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
            'psql' => env('BACKUP_PSQL', 'psql'),
        ],
        // The restore drill writes into this database only: the app's connection settings
        // with its own name (and optionally its own host and user). It must differ from
        // the app's database; the drill refuses otherwise.
        'drill' => [
            'database' => env('BACKUP_DRILL_DATABASE'),
            'host' => env('BACKUP_DRILL_HOST'),
            'username' => env('BACKUP_DRILL_USERNAME'),
            'password' => env('BACKUP_DRILL_PASSWORD'),
        ],
        'timeout_seconds' => (int) env('BACKUP_TIMEOUT', 3600),
    ],

];
