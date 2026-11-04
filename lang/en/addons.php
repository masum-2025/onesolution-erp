<?php

return [

    'errors' => [
        'addon_store_closed' => 'The add-on store is closed for this account. Ask your provider if you need another module.',
        'addon_not_allowed' => 'You cannot buy or cancel add-ons here. Ask the account owner or an administrator.',
        'addon_ask_provider' => 'Add-ons for this account are started by :provider. Contact them to add a module.',
        'addon_unknown' => 'There is no such add-on.',
        'addon_not_house' => 'Only the platform sets its own add-on prices. Set your own price for your clients instead.',
        'addon_not_for_sale' => ':module cannot be added to this account.',
        'addon_in_plan' => ':module is already in your plan. Turn it on under Modules.',
        'addon_already_running' => ':module is already added to this account.',
        'addon_no_price' => ':module has no price in :currency yet. Ask your provider.',
        'addon_trial_used' => 'This account has tried :module already. You can buy it.',
        'addon_no_trial' => 'Add-ons cannot be tried free here. You can buy one.',
        'addon_needs_first' => ':module needs :missing first. Add that, then try again.',
        'addon_not_running' => ':module is not added to this account.',
        'addon_op_reused' => 'This request was already used for something else. Reload the page and try again.',
        'addon_module_off' => ':module is turned off by your provider for every client. Ask them.',
    ],

    'messages' => [
        'trial_started' => 'The trial has started. The module is on for your account.',
        'bought' => 'Added. The module is on, and the first month\'s invoice is under Billing.',
        'bought_provider' => 'Added. The module is on; your provider bills you for it.',
        'bought_for_client' => 'Added for the client. The module is on.',
        'cancelled' => 'Cancelled. The module stays on until the end of the paid month; its data is kept.',
        'resumed' => 'Kept. The add-on carries on month by month.',
        'price_saved' => 'Price saved. It applies to new months from today.',
        'platform_price_saved' => 'Platform price saved. It applies to new months from today.',
    ],

];
