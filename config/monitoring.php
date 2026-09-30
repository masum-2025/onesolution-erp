<?php

/*
|--------------------------------------------------------------------------
| Monitoring, alerts and health (Phase 9-2)
|--------------------------------------------------------------------------
|
| Platform operations settings: they watch the whole platform, before any
| tenant is known, so they are configuration, not rules. Thresholds are
| placeholders until real traffic is measured.
|
*/

return [

    /*
    | Alert kinds. Each watches security events (SecurityLog) and opens an
    | alert when "threshold" events with the same "group_by" value arrive
    | within "window" seconds. An open alert of the same kind and group only
    | counts up for "cooldown" seconds; nobody is told twice.
    |
    | group_by: the first of these context fields that has a value.
    | notify:   platform (the operators below), person (the account the
    |           events are about), organization (people who may read its
    |           audit log), partner (the partner's owners).
    */
    'alerts' => [
        'brute_force_account' => [
            'events' => ['audit.auth.login_failed', 'audit.auth.second_step_failed', 'audit.identity.password_check_failed'],
            'group_by' => ['user_id'],
            'threshold' => 10, 'window' => 600, 'cooldown' => 3600,
            'severity' => 'high', 'notify' => ['platform', 'person'],
        ],
        'brute_force_address' => [
            'events' => ['audit.auth.login_failed', 'audit.auth.second_step_failed'],
            'group_by' => ['ip'],
            'threshold' => 30, 'window' => 600, 'cooldown' => 3600,
            'severity' => 'high', 'notify' => ['platform'],
        ],
        'access_denied_spike' => [
            'events' => ['access.denied'],
            'group_by' => ['user_id', 'ip'],
            'threshold' => 50, 'window' => 300, 'cooldown' => 3600,
            'severity' => 'medium', 'notify' => ['platform'],
        ],
        'rate_limited_spike' => [
            'events' => ['request.rate_limited'],
            'group_by' => ['ip'],
            'threshold' => 100, 'window' => 300, 'cooldown' => 3600,
            'severity' => 'medium', 'notify' => ['platform'],
        ],
        'cross_tenant_attempt' => [
            'events' => ['tenant.cross_access_attempt'],
            'group_by' => ['user_id', 'api_key_id', 'ip'],
            'threshold' => 1, 'window' => 3600, 'cooldown' => 3600,
            'severity' => 'high', 'notify' => ['platform'],
        ],
        'data_export' => [
            'events' => ['audit.data.export_requested', 'audit.audit.export_requested'],
            'group_by' => ['organization_id'],
            'threshold' => 1, 'window' => 60, 'cooldown' => 3600,
            'severity' => 'low', 'notify' => ['platform', 'organization'],
        ],
        'module_switched_off' => [
            'events' => ['audit.module.disabled', 'audit.module.purge_requested'],
            'group_by' => ['organization_id'],
            'threshold' => 1, 'window' => 60, 'cooldown' => 600,
            'severity' => 'medium', 'notify' => ['platform', 'organization'],
        ],
        'sensitive_rule_changed' => [
            'events' => ['audit.rule.changed', 'audit.rule.approved'],
            // Only rules their module marks as sensitive (maker-checker): approved changes,
            // and direct ones by platform tooling.
            'only_sensitive_rules' => true,
            'group_by' => ['organization_id', 'partner_id'],
            'threshold' => 1, 'window' => 60, 'cooldown' => 600,
            'severity' => 'medium', 'notify' => ['platform', 'organization'],
        ],
        'mfa_reset' => [
            'events' => ['audit.identity.mfa_reset_approved'],
            'group_by' => ['organization_id'],
            'threshold' => 1, 'window' => 60, 'cooldown' => 600,
            'severity' => 'medium', 'notify' => ['platform', 'organization'],
        ],
        'partner_api_key_created' => [
            'events' => ['audit.partner.api_key_created'],
            'group_by' => ['partner_id'],
            'threshold' => 1, 'window' => 60, 'cooldown' => 600,
            'severity' => 'medium', 'notify' => ['platform', 'partner'],
        ],
        'restore_drill_failed' => [
            'events' => ['audit.backup.restore_drill_failed'],
            'group_by' => ['source', 'ip'],
            'threshold' => 1, 'window' => 60, 'cooldown' => 3600,
            'severity' => 'high', 'notify' => ['platform'],
        ],
    ],

    // The platform operators. Each channel gets alerts from its minimum severity up.
    'platform' => [
        'locale' => env('MONITORING_LOCALE', 'en'),
        'mail' => [
            'to' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_MAIL_TO', ''))))),
            'min_severity' => env('MONITORING_MAIL_MIN', 'low'),
        ],
        'slack' => [
            'webhook' => env('MONITORING_SLACK_WEBHOOK'),
            'min_severity' => env('MONITORING_SLACK_MIN', 'medium'),
        ],
        'sms' => [
            'to' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_SMS_TO', ''))))),
            'min_severity' => env('MONITORING_SMS_MIN', 'high'),
        ],
    ],

    'health' => [
        // Monitoring tools read GET /internal/health with "Authorization: Bearer <token>".
        // Empty = the endpoint does not exist (404).
        'token' => env('HEALTH_TOKEN'),
        'queues' => ['default'],
        // Above these, a check reports "warn" (and "fail" at twice as much).
        'queue_depth_warn' => 500,
        'failed_jobs_warn' => 1,
        'sync_errors_warn' => 20,
        'rule_cache_hit_rate_warn_percent' => 80,
        'backup_max_age_hours' => 26,
        'drill_max_age_days' => 35,
        'scheduler_max_silence_minutes' => 5,
        // A client's data move (Phase 10) still unfinished after this long needs a look.
        'tenant_move_max_minutes' => 120,
    ],

];
