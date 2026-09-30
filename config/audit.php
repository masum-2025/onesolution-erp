<?php

/*
|--------------------------------------------------------------------------
| Audit log (Phase 9-1)
|--------------------------------------------------------------------------
|
| Platform settings only. How long an organization keeps its entries is a
| rule of the advanced_audit module (advanced_audit.retention_days,
| advanced_audit.money_retention_days); without the module, entries are kept.
|
*/

return [

    // Entries about money and pay. They follow the longer money retention rule.
    'money_actions' => [
        'billing.*', 'payments.*', 'payroll.*', 'accounting.*', 'client.transfer*',
        'partner_plan.*', 'organization.plan_changed',
    ],

    // Copies of every entry for an external, append-only store (a SIEM or log archive).
    'shipping' => [
        // none | log (JSON lines on the "audit" log channel, for the log collector)
        'driver' => env('AUDIT_SHIP_DRIVER', 'none'),
        'batch' => 500,
        // Entries younger than this wait for the next run, so a change still being
        // saved (with an earlier timestamp) is never skipped.
        'lag_seconds' => 60,
    ],

    // advanced_audit reports and exports.
    'report_max_days' => 92,
    'export_max_days' => 366,
    'export_max_rows' => 200000,

];
