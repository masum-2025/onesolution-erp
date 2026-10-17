<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Business numbers never live here; they are rules.
*/

use Modules\FactoryErp\Dashboard\FactoryWidgets;
use Modules\FactoryErp\Dashboard\LineEfficiency;
use Modules\FactoryErp\Dashboard\LineOutput;
use Modules\FactoryErp\Dashboard\QualityToday;
use Modules\FactoryErp\Dashboard\ShippingSoon;
use Modules\FactoryErp\Dashboard\TodayOutput;
use Modules\FactoryErp\Dashboard\WorkInProgress;
use Modules\FactoryErp\Offline\OutputSync;

return [
    'key' => 'factory_erp',
    'name' => 'factory_erp::module.name',
    'description' => 'factory_erp::module.description',
    'version' => '1.5.0',
    'category' => 'business',
    // Materials come from Inventory (its Stock service), buyers are CRM organization
    // contacts (its Organizations service); materials on the floor post to Accounting
    // as work in progress (its Ledger).
    'requires' => ['inventory', 'accounting', 'crm'],
    'sectors' => ['factory'],
    'plans' => ['*'],
    'permissions' => ['factory_erp.view', 'factory_erp.merchandise', 'factory_erp.issue', 'factory_erp.produce', 'factory_erp.qc', 'factory_erp.manage'],
    'rules' => [
        [
            'key' => 'factory_erp.consumption_basis',
            'type' => 'enum',
            // Garments write consumption per dozen; kept per style when it is made.
            'schema' => ['enum' => ['dozen', 'piece']],
            'default' => 'dozen',
            'label' => 'factory_erp::rules.consumption_basis.label',
            'description' => 'factory_erp::rules.consumption_basis.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'materials',
            'sort_order' => 10,
        ],
        [
            'key' => 'factory_erp.default_wastage_bp',
            'type' => 'integer',
            // Wastage a new BOM line starts with, in basis points (500 = 5%).
            'schema' => ['minimum' => 0, 'maximum' => 5000],
            'default' => 500,
            'label' => 'factory_erp::rules.default_wastage_bp.label',
            'description' => 'factory_erp::rules.default_wastage_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'materials',
            'sort_order' => 20,
        ],
        [
            'key' => 'factory_erp.order_currency',
            'type' => 'string',
            // Export orders are priced in the buyer's currency.
            'schema' => ['pattern' => '^[A-Z]{3}$'],
            'default' => 'USD',
            'label' => 'factory_erp::rules.order_currency.label',
            'description' => 'factory_erp::rules.order_currency.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'orders',
            'sort_order' => 30,
        ],
        [
            'key' => 'factory_erp.order_number_prefix',
            'type' => 'string',
            'schema' => ['pattern' => '^[A-Z0-9]{1,8}$'],
            'default' => 'FO',
            'label' => 'factory_erp::rules.order_number_prefix.label',
            'description' => 'factory_erp::rules.order_number_prefix.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'orders',
            'sort_order' => 40,
        ],
        [
            'key' => 'factory_erp.ship_warning_days',
            'type' => 'integer',
            // Orders shipping within this many days show on the home and, not started yet, in the bell.
            'schema' => ['minimum' => 1, 'maximum' => 120],
            'default' => 30,
            'label' => 'factory_erp::rules.ship_warning_days.label',
            'description' => 'factory_erp::rules.ship_warning_days.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'orders',
            'sort_order' => 50,
        ],
        [
            'key' => 'factory_erp.over_issue',
            'type' => 'enum',
            // More material to an order than its bill of materials needs: asked again (warn) or refused (block).
            'schema' => ['enum' => ['warn', 'block']],
            'default' => 'warn',
            'label' => 'factory_erp::rules.over_issue.label',
            'description' => 'factory_erp::rules.over_issue.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'floor',
            'sort_order' => 60,
        ],
        [
            'key' => 'factory_erp.over_cut_bp',
            'type' => 'integer',
            // Pieces cut above the order per size and colour, in basis points (300 = 3%).
            'schema' => ['minimum' => 0, 'maximum' => 2000],
            'default' => 300,
            'label' => 'factory_erp::rules.over_cut_bp.label',
            'description' => 'factory_erp::rules.over_cut_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'floor',
            'sort_order' => 70,
        ],
        [
            'key' => 'factory_erp.output_by',
            'type' => 'enum',
            // Sewing lines write what they made every hour, or once a day.
            'schema' => ['enum' => ['hour', 'day']],
            'default' => 'hour',
            'label' => 'factory_erp::rules.output_by.label',
            'description' => 'factory_erp::rules.output_by.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'floor',
            'sort_order' => 80,
        ],
        [
            'key' => 'factory_erp.reject_warn_bp',
            'type' => 'integer',
            // A line's rejects today at or above this share of what it made ring the bell (500 = 5%).
            'schema' => ['minimum' => 1, 'maximum' => 10000],
            'default' => 500,
            'label' => 'factory_erp::rules.reject_warn_bp.label',
            'description' => 'factory_erp::rules.reject_warn_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'floor',
            'sort_order' => 90,
        ],
        [
            'key' => 'factory_erp.shift_minutes',
            'type' => 'integer',
            // Minutes a line works in a day unless its supervisor writes otherwise.
            'schema' => ['minimum' => 60, 'maximum' => 1440],
            'default' => 480,
            'label' => 'factory_erp::rules.shift_minutes.label',
            'description' => 'factory_erp::rules.shift_minutes.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'country_specific' => true,
            'category' => 'efficiency',
            'sort_order' => 100,
        ],
        [
            'key' => 'factory_erp.target_efficiency_bp',
            'type' => 'integer',
            // The efficiency an hour's target is set at (6000 = 60%).
            'schema' => ['minimum' => 1000, 'maximum' => 10000],
            'default' => 6000,
            'label' => 'factory_erp::rules.target_efficiency_bp.label',
            'description' => 'factory_erp::rules.target_efficiency_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'efficiency',
            'sort_order' => 110,
        ],
        [
            'key' => 'factory_erp.efficiency_counts_helpers',
            'type' => 'boolean',
            // Whether helpers' minutes count as available minutes besides operators'.
            'default' => false,
            'label' => 'factory_erp::rules.efficiency_counts_helpers.label',
            'description' => 'factory_erp::rules.efficiency_counts_helpers.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'efficiency',
            'sort_order' => 120,
        ],
        [
            'key' => 'factory_erp.final_aql',
            'type' => 'enum',
            // The AQL of final inspections (columns of database/data/aql.php); buyers name it in their orders.
            'schema' => ['enum' => ['2.5', '4.0']],
            'default' => '2.5',
            'label' => 'factory_erp::rules.final_aql.label',
            'description' => 'factory_erp::rules.final_aql.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'quality',
            'sort_order' => 130,
        ],
        [
            'key' => 'factory_erp.ship_needs_final_pass',
            'type' => 'boolean',
            // An order ships only after its last final inspection passed.
            'default' => true,
            'label' => 'factory_erp::rules.ship_needs_final_pass.label',
            'description' => 'factory_erp::rules.ship_needs_final_pass.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'quality',
            'sort_order' => 140,
        ],
        [
            'key' => 'factory_erp.dhu_warn_bp',
            'type' => 'integer',
            // A line's end-line DHU today at or above this rings the bell (500 = 5 defects per hundred pieces).
            'schema' => ['minimum' => 1, 'maximum' => 100000],
            'default' => 500,
            'label' => 'factory_erp::rules.dhu_warn_bp.label',
            'description' => 'factory_erp::rules.dhu_warn_bp.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'quality',
            'sort_order' => 150,
        ],
        [
            'key' => 'factory_erp.piece_rate_per',
            'type' => 'enum',
            // Operations' piece rates are written per piece, or per dozen (pay = pieces × rate ÷ 12, half up).
            'schema' => ['enum' => ['piece', 'dozen']],
            'default' => 'piece',
            'label' => 'factory_erp::rules.piece_rate_per.label',
            'description' => 'factory_erp::rules.piece_rate_per.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'pay',
            'sort_order' => 160,
        ],
        [
            'key' => 'factory_erp.piece_ticket_self',
            'type' => 'boolean',
            // Whether someone may write their own piece ticket (pay); off: another person writes it.
            'default' => false,
            'label' => 'factory_erp::rules.piece_ticket_self.label',
            'description' => 'factory_erp::rules.piece_ticket_self.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company'],
            'category' => 'pay',
            'sort_order' => 170,
        ],
    ],
    'menu' => [
        [
            'key' => 'factory_erp',
            'label' => 'factory_erp::module.menu',
            'route' => '/factory',
            'icon' => 'factory',
            'order' => 70,
            'children' => [
                ['key' => 'orders', 'label' => 'factory_erp::module.menu_orders', 'route' => '/factory', 'permission' => 'factory_erp.view'],
                ['key' => 'styles', 'label' => 'factory_erp::module.menu_styles', 'route' => '/factory/styles', 'permission' => 'factory_erp.view'],
                ['key' => 'output', 'label' => 'factory_erp::module.menu_output', 'route' => '/factory/output', 'permission' => 'factory_erp.view'],
                ['key' => 'qc', 'label' => 'factory_erp::module.menu_qc', 'route' => '/factory/qc', 'permission' => 'factory_erp.view'],
                ['key' => 'tickets', 'label' => 'factory_erp::module.menu_tickets', 'route' => '/factory/tickets', 'permission' => 'factory_erp.produce'],
            ],
        ],
    ],
    'quick_actions' => [
        ['key' => 'order', 'label' => 'factory_erp::module.new_order', 'route' => '/factory?new=1', 'permission' => 'factory_erp.merchandise', 'icon' => 'factory'],
    ],
    'events' => [],
    // Materials on the floor until finished goods come in (Accounting maps it to an account).
    'ledger_accounts' => [
        'factory_erp.wip' => ['label' => 'factory_erp::factory.posting_keys.wip', 'type' => 'asset'],
    ],
    'attention' => [FactoryWidgets::class, TodayOutput::class, QualityToday::class],
    // Sewing output written on a phone without a network (not money).
    'sync_records' => [OutputSync::class],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => [
        ['key' => 'open', 'label' => 'factory_erp::dashboard.open', 'type' => 'stat', 'provider' => FactoryWidgets::class, 'permission' => 'factory_erp.view', 'overview' => true],
        ['key' => 'shipping', 'label' => 'factory_erp::dashboard.shipping', 'type' => 'list', 'size' => 2, 'provider' => ShippingSoon::class, 'permission' => 'factory_erp.view'],
        ['key' => 'today', 'label' => 'factory_erp::dashboard.today', 'type' => 'stat', 'provider' => TodayOutput::class, 'permission' => 'factory_erp.view', 'overview' => true],
        ['key' => 'lines', 'label' => 'factory_erp::dashboard.lines', 'type' => 'bars', 'size' => 2, 'provider' => LineOutput::class, 'permission' => 'factory_erp.view'],
        ['key' => 'wip', 'label' => 'factory_erp::dashboard.wip', 'type' => 'stat', 'provider' => WorkInProgress::class, 'permission' => 'factory_erp.view'],
        ['key' => 'efficiency', 'label' => 'factory_erp::dashboard.efficiency', 'type' => 'bars', 'size' => 2, 'provider' => LineEfficiency::class, 'permission' => 'factory_erp.view'],
        ['key' => 'quality', 'label' => 'factory_erp::dashboard.quality', 'type' => 'stat', 'provider' => QualityToday::class, 'permission' => 'factory_erp.view'],
    ]],
    'settings' => ['pages' => [
        ['key' => 'defects', 'label' => 'factory_erp::module.menu_defects', 'route' => '/factory/defects', 'permission' => 'factory_erp.manage'],
        ['key' => 'lines', 'label' => 'factory_erp::module.menu_lines', 'route' => '/factory/lines', 'permission' => 'factory_erp.manage'],
        ['key' => 'fields', 'label' => 'factory_erp::module.menu_fields', 'route' => '/factory/fields', 'permission' => 'factory_erp.manage'],
    ]],
];
