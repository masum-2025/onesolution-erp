<?php

/*
|--------------------------------------------------------------------------
| Platform-, plan- and partner-level rule values (data, not code)
|--------------------------------------------------------------------------
|
| Country defaults that every country has (weekend, fiscal year, week start,
| date format, payment gateways) come from database/data/countries
| (countries:sync). Country values here are legal / financial facts and MUST be reviewed by a
| qualified accountant or lawyer before production use. Each entry records
| its source in `reason`, which goes into the audit log.
|
| Money is in minor units (poisha for BDT): 350,000 BDT = 35000000.
|
*/

return [

    // ── Bangladesh ───────────────────────────────────────────────────────
    [
        'key' => 'payroll.overtime_multiplier',
        'country' => 'BD',
        'value' => '2.0',
        'reason' => 'Bangladesh Labour Act 2006, s.108: overtime at twice the ordinary rate.',
    ],
    [
        'key' => 'hrm.notice_period_days',
        'country' => 'BD',
        'value' => 60,
        'reason' => 'Bangladesh Labour Act 2006, s.27: resignation notice for permanent workers.',
    ],
    [
        'key' => 'payroll.tax_slabs',
        'country' => 'BD',
        'effective_from' => '2024-07-01',
        // Cumulative taxable income thresholds, individual taxpayer (general), FY 2024-25.
        'value' => [
            ['upto_minor' => 35000000, 'rate_percent' => '0'],
            ['upto_minor' => 45000000, 'rate_percent' => '5'],
            ['upto_minor' => 85000000, 'rate_percent' => '10'],
            ['upto_minor' => 135000000, 'rate_percent' => '15'],
            ['upto_minor' => 185000000, 'rate_percent' => '20'],
            ['upto_minor' => 385000000, 'rate_percent' => '25'],
            ['upto_minor' => null, 'rate_percent' => '30'],
        ],
        'reason' => 'Bangladesh individual income tax slabs FY 2024-25 (Finance Act 2024). Verify with a tax adviser before use.',
    ],

    [
        'key' => 'payroll.bonus_min_service_months',
        'country' => 'BD',
        'value' => 12,
        'reason' => 'Bangladesh Labour Rules 2015, r.111(5): festival bonus after one year of continuous service. Verify with an adviser.',
    ],
    [
        'key' => 'payroll.bonus_percent_of_basic',
        'country' => 'BD',
        'value' => '100',
        'reason' => 'Bangladesh Labour Rules 2015, r.111(5): each of two yearly festival bonuses up to one month\'s basic. Verify with an adviser.',
    ],

    // ── Plan defaults (plans: database/seeders/data/plans.php) ──────────
    ['key' => 'offline_mode.max_cached_records', 'plan' => 'starter', 'value' => 2000, 'reason' => 'Starter plan storage limit.'],
    ['key' => 'offline_mode.max_cached_records', 'plan' => 'business', 'value' => 10000, 'reason' => 'Business plan storage limit.'],
    ['key' => 'offline_mode.max_cached_records', 'plan' => 'enterprise', 'value' => 50000, 'reason' => 'Enterprise plan storage limit.'],

    // Usage limits (PLACEHOLDER numbers until the business confirms them).
    // Enterprise has no values here, so it stays unlimited.
    ['key' => 'plans.max_users', 'plan' => 'starter', 'value' => 10, 'reason' => 'Starter plan: up to 10 staff.'],
    ['key' => 'plans.max_users', 'plan' => 'business', 'value' => 50, 'reason' => 'Business plan: up to 50 staff.'],
    ['key' => 'plans.max_branches', 'plan' => 'starter', 'value' => 2, 'reason' => 'Starter plan: up to 2 branches.'],
    ['key' => 'plans.max_branches', 'plan' => 'business', 'value' => 20, 'reason' => 'Business plan: up to 20 branches.'],
    ['key' => 'plans.max_storage_mb', 'plan' => 'starter', 'value' => 5120, 'reason' => 'Starter plan: 5 GB.'],
    ['key' => 'plans.max_storage_mb', 'plan' => 'business', 'value' => 51200, 'reason' => 'Business plan: 50 GB.'],
    // The house partner (One Solutions itself) takes self-serve sign-ups (Phase 5C).
    ['key' => 'b2c.self_signup_allowed', 'partner' => 'house', 'value' => true, 'reason' => 'One Solutions sells to individuals directly.'],

    // Personal plans (Phase 5C): one person; a team upgrades to a company.
    ['key' => 'plans.max_users', 'plan' => 'personal_free', 'value' => 1, 'reason' => 'Personal plan: one person.'],
    ['key' => 'plans.max_users', 'plan' => 'personal_plus', 'value' => 1, 'reason' => 'Personal plan: one person.'],
    // One person keeps the books alone: nobody else could approve (separation of duties).
    ['key' => 'access.separation_of_duties', 'plan' => 'personal_free', 'value' => [], 'reason' => 'Personal plan: one person, no second approver.'],
    ['key' => 'access.separation_of_duties', 'plan' => 'personal_plus', 'value' => [], 'reason' => 'Personal plan: one person, no second approver.'],
    ['key' => 'accounting.year_reopen_needs_second_person', 'plan' => 'personal_free', 'value' => false, 'reason' => 'Personal plan: one person reopens their own year.'],
    ['key' => 'accounting.year_reopen_needs_second_person', 'plan' => 'personal_plus', 'value' => false, 'reason' => 'Personal plan: one person reopens their own year.'],
    ['key' => 'plans.max_storage_mb', 'plan' => 'personal_free', 'value' => 500, 'reason' => 'Personal Free: 500 MB.'],
    ['key' => 'plans.max_storage_mb', 'plan' => 'personal_plus', 'value' => 5120, 'reason' => 'Personal Plus: 5 GB.'],
    ['key' => 'offline_mode.max_cached_records', 'plan' => 'personal_plus', 'value' => 2000, 'reason' => 'Personal Plus storage limit.'],

];
