<?php

return [

    'errors' => [
        'invoice_not_found' => 'This invoice does not exist or is not yours to see.',
        'plan_not_found' => 'This plan does not exist.',
        'not_payable' => 'Only an unpaid invoice can be marked paid.',
        'not_creditable' => 'This document cannot be credited: it is a credit note or already fully credited.',
        'credit_too_large' => 'A credit must be between 1 and :remaining (:currency, before tax), what is left to credit on this invoice.',
        'nothing_to_pay' => 'There is no commission to pay in :currency.',
        'role_not_allowed' => 'Only the owner and billing staff can see billing.',
        'top_level_only' => 'Billing belongs to :organization. Ask someone who manages it.',
        'duplicate_price' => 'There is already a price for this currency and period.',
    ],

    'messages' => [
        'plan_created' => 'The plan ":name" was created.',
        'plan_updated' => 'The plan ":name" was saved.',
        'plan_archived' => 'The plan ":name" is archived. Clients already on it keep it.',
    ],

    // Frozen on invoices when issued.
    'lines' => [
        'wholesale_client' => ':client: :plan plan, :month',
        'wholesale_seats' => ':client: :plan plan, staff users, :month',
        'subscription_monthly' => ':plan plan, monthly, :from to :to',
        'subscription_yearly' => ':plan plan, yearly, :from to :to',
        'credit' => 'Credit for invoice :number',
    ],

    // Billing run report (operators).
    'run' => [
        'no_wholesale_price' => 'no wholesale price for :client on plan :plan in :currency',
        'no_client_price' => ':client has no price in :currency per :period; set one on its plan',
    ],
];
