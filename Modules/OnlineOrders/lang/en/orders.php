<?php

// Online orders: errors (what to do next), checks, book entries.
return [
    'errors' => [
        'not_company_unit' => 'Online orders belong to a company or a branch. Open one of them and try again.',
        'no_currency' => 'The company has no currency yet. Set it in the company\'s settings first.',
        'order_not_found' => 'This order was not found here. It may belong to another branch.',
        'warehouse_unusable' => 'This warehouse is not in use. Pick another one for the order.',
        'item_not_sold' => 'Item :item has no selling price or is switched off. Set its price in Inventory or remove it from the order.',
        'not_enough_stock' => 'Not enough :item in the warehouse: :available can still go out. Lower the quantity or receive more stock.',
        'cod_not_allowed' => 'Cash on delivery is off here. Choose "Paid before sending".',
        'unknown_zone' => 'Delivery area ":zone" is not in the list. Pick one of the areas set in the rules.',
        'discount_limit' => 'The discount is above :percent% of the goods. Ask a supervisor to make it.',
        'wrong_status' => 'This order is :status, so it cannot be moved that way (:action). Refresh to see where it stands.',
        'needs_supervisor' => 'Only a supervisor can do this to an order already sent out.',
        'prepaid_not_paid' => 'This order is paid before sending: :due is still owed. Record the payment first.',
        'overpaid' => 'More than is owed. At most :due can be taken for this order.',
        'nothing_due' => 'Nothing is owed on this order.',
        'refund_method_missing' => ':amount was paid for this order. Choose how it is given back.',
        'return_too_late' => 'Orders can be sent back within :days days of delivery; this one is past that.',
        'version_conflict' => 'Someone changed this order a moment ago. Refresh to see it, then try again.',
    ],
    'statuses' => [
        'draft' => 'a draft', 'confirmed' => 'confirmed', 'packed' => 'packed', 'shipped' => 'sent out',
        'delivered' => 'delivered', 'cancelled' => 'cancelled', 'returned' => 'taken back',
    ],
    'actions' => [
        'revise' => 'change', 'confirm' => 'confirm', 'pack' => 'pack', 'ship' => 'send out', 'deliver' => 'mark delivered',
        'pay' => 'take a payment', 'cancel' => 'cancel', 'return' => 'take back',
    ],
    'validation' => [
        'quantity' => 'The quantity has more decimals than the item\'s unit allows.',
        'phone' => 'Type a mobile number that can be called, e.g. 01712345678.',
    ],
    'narration' => [
        'delivered' => 'Online order :number delivered',
        'returned' => 'Online order :number taken back',
        'payment' => 'Payment for online order :number',
        'refund' => 'Money back for online order :number',
    ],
    'posting_keys' => [
        'receivable' => 'Owed by online customers',
        'sales' => 'Online sales',
        'delivery_income' => 'Delivery charges',
        'tax_output' => 'VAT on online sales',
        'cash' => 'Cash from online orders',
        'mobile' => 'Mobile wallet (online orders)',
        'bank' => 'Bank (online orders)',
    ],
];
