<?php

/*
|--------------------------------------------------------------------------
| Default add-on prices (data, not code)
|--------------------------------------------------------------------------
|
| Monthly prices of modules an account buys beyond its plan: list_minor is
| what a client pays (a partner may set its own in its console),
| wholesale_minor what a wholesale partner pays us per client.
| `php artisan addons:sync-prices` records these; `php artisan addons:price`
| sets one. A module without a price here is not for sale. Integer minor
| units, never floats.
|
| PLACEHOLDER prices until the business confirms them.
|
*/

$prices = [
    // module => [BDT list, BDT wholesale, USD list, USD wholesale]
    'projects' => [100000, 60000, 1000, 600],
    'compliance' => [150000, 90000, 1500, 900],
    'recruitment' => [80000, 50000, 800, 500],
    'appraisals' => [80000, 50000, 800, 500],
    'fleet' => [80000, 50000, 800, 500],
    'zkteco' => [50000, 30000, 500, 300],
    'custom_reports' => [100000, 60000, 1000, 600],
    'advanced_audit' => [120000, 70000, 1200, 700],
    'business_network' => [80000, 50000, 800, 500],
    'energy_monitoring' => [120000, 70000, 1200, 700],
    'carbon_management' => [120000, 70000, 1200, 700],
    'document_ai' => [150000, 90000, 1500, 900],
    'ai_assistant' => [150000, 90000, 1500, 900],

    // Every other module, by size (placeholders; the platform changes them on its Add-ons page).
    // Large business modules.
    'accounting' => [200000, 120000, 2000, 1200],
    'payroll' => [200000, 120000, 2000, 1200],
    'inventory' => [200000, 120000, 2000, 1200],
    'pos' => [200000, 120000, 2000, 1200],
    'education' => [200000, 120000, 2000, 1200],
    'factory_erp' => [200000, 120000, 2000, 1200],
    // Standard modules.
    'hrm' => [100000, 60000, 1000, 600],
    'attendance' => [100000, 60000, 1000, 600],
    'crm' => [100000, 60000, 1000, 600],
    'online_orders' => [100000, 60000, 1000, 600],
    'social_commerce' => [100000, 60000, 1000, 600],
    'leave' => [100000, 60000, 1000, 600],
    'expenses' => [100000, 60000, 1000, 600],
    'education_fees' => [100000, 60000, 1000, 600],
    'education_exams' => [100000, 60000, 1000, 600],
    // Small modules.
    'letters' => [50000, 30000, 500, 300],
    'course_registration' => [50000, 30000, 500, 300],
    'education_lectures' => [50000, 30000, 500, 300],
    'student_attendance' => [50000, 30000, 500, 300],
    'teacher_feedback' => [50000, 30000, 500, 300],
    'edu_website' => [50000, 30000, 500, 300],
    // Platform features.
    'client_portal' => [80000, 50000, 800, 500],
    'online_payments' => [80000, 50000, 800, 500],
    'offline_mode' => [80000, 50000, 800, 500],
    'multi_language' => [80000, 50000, 800, 500],
    'multi_currency' => [80000, 50000, 800, 500],
    'api_integration' => [80000, 50000, 800, 500],
    'external_integrations' => [80000, 50000, 800, 500],
    // New modules (make:erp-module adds them here).
];

$entries = [];
foreach ($prices as $module => [$bdtList, $bdtWholesale, $usdList, $usdWholesale]) {
    $entries[] = ['module' => $module, 'currency' => 'BDT', 'list_minor' => $bdtList, 'wholesale_minor' => $bdtWholesale];
    $entries[] = ['module' => $module, 'currency' => 'USD', 'list_minor' => $usdList, 'wholesale_minor' => $usdWholesale];
}

return $entries;
