<?php

// POS messages: errors, validation, journal narrations, posting keys.
return [
    'errors' => [
        'not_company_unit' => 'Choose a company, branch or department; a group has no counters.',
        'no_currency' => 'The company has no currency yet. Set its country or currency first.',
        'register_not_found' => 'Counter not found here. Choose one of this branch\'s counters.',
        'session_not_found' => 'Shift not found. Reload the list.',
        'sale_not_found' => 'Sale not found. Check the receipt number.',
        'register_inactive' => 'This counter is switched off.',
        'session_open' => 'This counter already has a shift open. Close it first.',
        'no_open_session' => 'Open a shift at this counter first.',
        'session_not_open' => 'This shift is already closed.',
        'not_pending' => 'This shift is not waiting for review.',
        'own_session' => 'Another supervisor has to review a shift you opened or closed.',
        'underpaid' => 'Paid less than owed. Take the rest.',
        'change_without_cash' => 'Change can only be given from cash. Lower the card or wallet amount.',
        'method_not_taken' => 'This counter does not take :method.',
        'discount_limit' => 'The discount is above :percent% of the sale. Ask a supervisor.',
        'item_not_sold' => ':item cannot be sold here (no price, or switched off).',
        'return_too_much' => 'More :item than was sold (less what came back already).',
        'return_too_late' => 'Goods can be returned within :days days of the sale.',
        'version_conflict' => 'Someone changed this meanwhile. Reload and try again.',
    ],
    'validation' => [
        'report_range' => 'A report covers up to :days days. Choose a shorter range.',
        'code_taken' => 'Another counter of this company already has this code.',
        'unit' => 'Choose a branch or department of this company.',
        'warehouse' => 'Choose a warehouse of this company that is in use.',
        'methods' => 'Choose cash, card or mobile wallet.',
        'quantity' => 'Give a quantity above zero (no more decimals than the unit allows).',
        'line' => 'Choose a line of this sale.',
        'refund_total' => 'What is paid back must add up to the return.',
    ],
    'narration' => [
        'sale' => 'Sale :number',
        'return' => 'Return :number (sale :sale)',
        'variance' => 'Cash difference at counter :register',
    ],
    'posting_keys' => [
        'cash' => 'Cash at the counters',
        'card' => 'Card payments to receive',
        'mobile' => 'Mobile wallet payments to receive',
        'sales' => 'Sales at the counter',
        'tax_output' => 'VAT on counter sales (payable)',
        'cash_variance' => 'Cash over and short',
    ],
];
