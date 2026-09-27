<?php

return [

    'errors' => [
        'not_self_serve' => 'This account\'s plan is managed by its provider, not bought online here.',
        'billed_by_provider' => ':provider manages this account\'s plan and bills. Ask them to change it.',
        'not_allowed' => 'Only the account owner, or someone allowed to manage billing, can buy or pay.',
        'unverified' => 'Please confirm your email or phone first (My account), then try again.',
        'plan_not_offered' => 'This plan cannot be bought here. Pick one of the plans shown.',
        'no_price' => 'This plan has no price for your currency and period. Pick another period or plan.',
        'already_on_plan' => 'You already have this plan, paid until :until. You can renew when that period ends.',
        'change_at_period_end' => 'Your current plan is paid until :until. You can switch plans when that period ends.',
        'pay_open_invoice' => 'Please pay invoice :number first (or move to the free plan), then choose a new plan.',
        'no_gateway' => 'Online payment is not available for your country or currency yet. Please contact support.',
        'gateway_unavailable' => 'The payment service did not answer. Nothing was charged; please try again in a few minutes.',
        'payment_not_found' => 'This payment does not exist or is not yours to see.',
        'invoice_not_payable' => 'This invoice is already paid or cancelled, or it is not yours.',
        'op_reused' => 'This payment request was already used for something else. Please reload the page and try again.',
        'trials_off' => 'Free trials are not offered for this account.',
        'trial_used' => 'A free trial was already used with this account, email or phone. You can buy the plan instead.',
        'trial_not_from_free' => 'A free trial can only start from the free plan.',
        'already_free' => 'You are already on the free plan.',
        'nothing_to_keep' => 'There is no scheduled change to undo.',
        'no_free_plan' => 'A company has no free plan. Pay the open invoice to keep working; your data stays safe either way.',
    ],

    'messages' => [
        'trial_started' => 'Your free trial has started. Enjoy!',
        'moved_to_free' => 'You are now on the free plan. All your data is kept.',
        'moves_to_free' => 'You keep your plan until the paid period ends. You move to the free plan on :date.',
        'plan_kept' => 'Done: you keep your plan and it renews as usual.',
    ],

];
