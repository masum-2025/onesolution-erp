<?php

// Factory messages, errors and checks.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its units: a group has no factory of its own.',
        'no_currency' => 'Set the company\'s currency first (company settings).',
        'style_not_found' => 'That style does not exist here.',
        'order_not_found' => 'That order does not exist here.',
        'field_not_found' => 'Field not found. Reload the page.',
        'version_conflict' => 'Someone changed this a moment ago. It is fresh now; make your change again.',
        'wrong_status' => 'This cannot be done now: the order has moved on (in production, shipped or cancelled orders do not change).',
        'size_in_use' => 'An order still has pieces in that size. Change the order first.',
        'color_in_use' => 'An order still has pieces in that colour. Change the order first.',
        'not_a_buyer' => 'Choose a buyer from CRM (an organization tagged "buyer"). CRM must be on.',
        'not_in_style' => '":value" is not a size or colour of this style. Add it to the style first.',
        'not_an_item' => 'Each material must be an active Inventory item.',
        'no_pieces' => 'Put pieces in at least one size and colour before confirming.',
    ],
    'validation' => [
        'list_empty' => 'Give at least one.',
        'code_taken' => 'Another style already has this number.',
        'field_key_taken' => 'Another field here already uses this key.',
        'field_too_many' => 'At most :max fields of each kind.',
        'field_options' => 'A choice field needs at least one choice.',
        'field_unknown' => 'This field is not asked for here.',
        'field_required' => ':field is needed.',
        'field_text' => 'Up to :max letters.',
        'field_number' => 'Write a number, like 12 or 3.5.',
        'field_money' => 'Write an amount in :currency.',
        'field_date' => 'Write a date, like 2026-11-30.',
        'field_choice' => 'Choose one of the choices.',
        'field_yes_no' => 'Choose yes or no.',
    ],
];
