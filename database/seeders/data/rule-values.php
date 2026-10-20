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
        'key' => 'leave.default_types',
        'country' => 'BD',
        'value' => [
            ['code' => 'CL', 'name' => ['en' => 'Casual leave', 'bn' => 'নৈমিত্তিক ছুটি'], 'paid' => true, 'accrual' => 'yearly', 'days' => 10, 'half_day' => true],
            ['code' => 'SL', 'name' => ['en' => 'Sick leave', 'bn' => 'অসুস্থতাজনিত ছুটি'], 'paid' => true, 'accrual' => 'yearly', 'days' => 14, 'half_day' => true, 'document_after_days' => 2],
            ['code' => 'EL', 'name' => ['en' => 'Earned leave', 'bn' => 'অর্জিত ছুটি'], 'paid' => true, 'accrual' => 'worked_days', 'worked_days_per_day' => 18, 'carry_max_days' => 40, 'half_day' => false, 'encashable' => true],
            ['code' => 'ML', 'name' => ['en' => 'Maternity leave', 'bn' => 'মাতৃত্বকালীন ছুটি'], 'paid' => true, 'accrual' => 'yearly', 'days' => 112, 'half_day' => false, 'counts_days_off' => true, 'gender' => 'female'],
            ['code' => 'LWP', 'name' => ['en' => 'Leave without pay', 'bn' => 'বিনা বেতনে ছুটি'], 'paid' => false, 'accrual' => 'yearly', 'days' => 0, 'half_day' => true],
        ],
        'reason' => 'Bangladesh Labour Act 2006: casual 10 days (s.115), sick 14 days (s.116), earned one day per 18 days worked, kept up to 40 days (s.117), maternity 8 weeks before and 8 after birth (s.46). PLACEHOLDER: a lawyer must confirm for each sector (shops, factories and services differ).',
    ],
    [
        'key' => 'student_attendance.weekend_days',
        'country' => 'BD',
        'value' => ['fri', 'sat'],
        'reason' => 'Ministry of Education, 2022: schools and colleges close on Friday and Saturday every week.',
    ],
    [
        'key' => 'factory_erp.export_permit_required',
        'country' => 'BD',
        'value' => true,
        'reason' => 'Bangladesh Bank: every export shipment needs an EXP form certified by the exporter\'s bank.',
    ],
    [
        'key' => 'factory_erp.export_permit_label',
        'country' => 'BD',
        'value' => 'EXP',
        'reason' => 'Bangladesh Bank calls the export permit the EXP form.',
    ],
    [
        'key' => 'education_exams.default_grading_scheme',
        'country' => 'BD',
        'value' => 'bd_school_gpa5',
        'reason' => 'SSC/HSC letter grades (GPA 5); universities choose the UGC scale (bd_university_ugc) per exam.',
    ],
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

    [
        'key' => 'payroll.gratuity_min_years',
        'country' => 'BD',
        'value' => 5,
        'reason' => 'Bangladesh Labour Act 2006, s.2(10) and company gratuity schemes: commonly from five years of service. PLACEHOLDER: verify with an adviser.',
    ],
    [
        'key' => 'payroll.gratuity_days_per_year',
        'country' => 'BD',
        'value' => 30,
        'reason' => 'Bangladesh Labour Act 2006, s.2(10): thirty days of wages for each completed year. PLACEHOLDER: verify with an adviser.',
    ],
    [
        'key' => 'payroll.pf_vesting',
        'country' => 'BD',
        'value' => [['after_years' => 3, 'percent' => '50'], ['after_years' => 5, 'percent' => '100']],
        'reason' => 'Bangladesh Labour Act 2006, s.264: the company share after three years (part) and five years (all). PLACEHOLDER: verify with an adviser.',
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
    // A school website on its own domain (WEB-3a) comes with the bigger plans.
    ['key' => 'edu_website.own_domain', 'plan' => 'business', 'value' => true, 'reason' => 'Business plan: the website on a domain the school owns.'],
    ['key' => 'edu_website.own_domain', 'plan' => 'enterprise', 'value' => true, 'reason' => 'Enterprise plan: the website on a domain the school owns.'],
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
