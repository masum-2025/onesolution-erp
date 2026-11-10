<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers live in rules, never here. Patients come from the Patients
| module's PatientDirectory; other departments add charges through
| Modules\PatientBilling\Contracts\PatientCharges; the books (when Accounting
| is on) through Accounting's public Ledger.
*/

use Modules\PatientBilling\Dashboard\BillingWidgets;

$rule = fn (string $name, string $type, mixed $default, array $levels, string $category, int $order, array $extra = []) => [
    'key' => "patient_billing.{$name}",
    'type' => $type,
    'default' => $default,
    'label' => "patient_billing::rules.{$name}.label",
    'description' => "patient_billing::rules.{$name}.description",
    'overridable_levels' => $levels,
    'category' => $category,
    'sort_order' => $order,
    ...$extra,
];
$toCompany = ['platform', 'partner', 'group', 'company'];
$toBranch = ['platform', 'partner', 'group', 'company', 'branch'];

return [
    'key' => 'patient_billing',
    'name' => 'patient_billing::module.name',
    'description' => 'patient_billing::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => ['patients'],
    // Any sector may turn it on; the diagnostic and hospital packages (H-5) turn it on by default.
    'sectors' => ['*'],
    'plans' => ['*'],
    'permissions' => ['patient_billing.view', 'patient_billing.collect', 'patient_billing.approve', 'patient_billing.manage', 'patient_billing.close_day'],
    'rules' => [
        $rule('discount_approval_above_percent', 'integer', 10, $toCompany, 'discounts', 10, ['schema' => ['minimum' => 0, 'maximum' => 100], 'sensitive' => true]),
        $rule('allow_due', 'boolean', true, $toBranch, 'billing', 20),
        $rule('prices_include_tax', 'boolean', true, $toCompany, 'billing', 30, ['country_specific' => true]),
        $rule('require_cash_day', 'boolean', true, $toBranch, 'cash', 40),
        $rule('cash_variance_allowed', 'integer', 0, $toBranch, 'cash', 50, ['schema' => ['minimum' => 0, 'maximum' => 100000000]]),
        $rule('number_prefixes', 'json', ['bill' => 'B', 'receipt' => 'MR', 'refund' => 'RF'], $toCompany, 'numbering', 60, ['schema' => [
            'type' => 'object',
            'properties' => [
                'bill' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{0,6}$'],
                'receipt' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{0,6}$'],
                'refund' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{0,6}$'],
            ],
            'additionalProperties' => false,
        ]]),
    ],
    'menu' => [
        [
            'key' => 'patient_billing',
            'label' => 'patient_billing::module.menu',
            'route' => '/patient-billing/bills',
            'icon' => 'receipt',
            'order' => 58,
            'section' => 'business',
            'children' => [
                ['key' => 'bills', 'label' => 'patient_billing::module.menu_bills', 'route' => '/patient-billing/bills', 'permission' => 'patient_billing.view'],
                ['key' => 'services', 'label' => 'patient_billing::module.menu_services', 'route' => '/patient-billing/services', 'permission' => 'patient_billing.view'],
                ['key' => 'approvals', 'label' => 'patient_billing::module.menu_approvals', 'route' => '/patient-billing/approvals', 'permission' => 'patient_billing.approve'],
                ['key' => 'panels', 'label' => 'patient_billing::module.menu_panels', 'route' => '/patient-billing/panels', 'permission' => 'patient_billing.view'],
                ['key' => 'cash', 'label' => 'patient_billing::module.menu_cash', 'route' => '/patient-billing/cash', 'permission' => 'patient_billing.collect'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'new_bill', 'label' => 'patient_billing::module.new_bill', 'route' => '/patient-billing/bills?new=1', 'permission' => 'patient_billing.collect', 'icon' => 'receipt'],
    ],
    // Payloads carry ids and amounts only (never a patient's name).
    'events' => ['patient_billing.bill_finalized', 'patient_billing.payment_received', 'patient_billing.refund_made', 'patient_billing.day_closed'],
    // Money into the books (Accounting maps each to an account).
    'ledger_accounts' => [
        'patient_billing.cash' => ['label' => 'patient_billing::module.posting_keys.cash', 'type' => 'asset'],
        'patient_billing.card' => ['label' => 'patient_billing::module.posting_keys.card', 'type' => 'asset'],
        'patient_billing.mobile' => ['label' => 'patient_billing::module.posting_keys.mobile', 'type' => 'asset'],
        'patient_billing.receivable' => ['label' => 'patient_billing::module.posting_keys.receivable', 'type' => 'asset'],
        'patient_billing.panel_receivable' => ['label' => 'patient_billing::module.posting_keys.panel_receivable', 'type' => 'asset'],
        'patient_billing.advance' => ['label' => 'patient_billing::module.posting_keys.advance', 'type' => 'liability'],
        'patient_billing.income' => ['label' => 'patient_billing::module.posting_keys.income', 'type' => 'income'],
        'patient_billing.tax_output' => ['label' => 'patient_billing::module.posting_keys.tax_output', 'type' => 'liability'],
        'patient_billing.refunds' => ['label' => 'patient_billing::module.posting_keys.refunds', 'type' => 'income'],
        'patient_billing.cash_variance' => ['label' => 'patient_billing::module.posting_keys.cash_variance', 'type' => 'expense'],
    ],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'collected_today', 'label' => 'patient_billing::dashboard.collected_today', 'type' => 'stat', 'provider' => BillingWidgets::class, 'permission' => 'patient_billing.view'],
    ]],
    'attention' => [BillingWidgets::class],
    'settings' => ['pages' => []],
];
