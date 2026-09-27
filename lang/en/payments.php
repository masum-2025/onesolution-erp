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
        // A client's own merchant accounts (Phase 6).
        'not_company' => 'Payment gateway accounts are set for the whole company. Open this from the company, not a branch or department.',
        'gateway_not_offered' => 'This payment gateway is not available for your company. Pick one of the gateways shown.',
        'live_not_allowed' => 'Live (real money) accounts are not open yet. Connect a sandbox (test) account for now.',
        'currency_not_supported' => 'This gateway does not take :currency, your company\'s currency.',
        'account_exists' => 'An account for this gateway is already connected. Change that one instead.',
        'account_not_found' => 'This payment gateway account does not exist or is not yours to see.',
        'check_rejected_credentials' => 'The gateway did not accept this store id and password. Copy them again from your gateway account and try once more.',
        'check_store_inactive' => 'The gateway says this store is not active yet. Ask the gateway to activate it, then try again.',
        'check_unreachable' => 'The gateway did not answer. Nothing was saved; please try again in a few minutes.',
        'check_unexpected' => 'The gateway gave an answer we did not expect. Nothing was saved; please try again or contact support.',
        'stale' => 'Someone changed this account a moment ago. Reload the page to see the latest, then try again.',
        'nothing_pending' => 'There is no change waiting for approval.',
        'own_change' => 'Someone else must approve a change you made. If nobody else can, it takes effect by itself after the waiting time.',
        'not_disabled' => 'This account is already on.',
        'never_approved' => 'This account was never approved. Connect it again with its store details.',
        'kind_not_available' => 'Online payment for this is not available here.',
        'nothing_due' => 'There is nothing to pay for this right now.',
        'no_merchant_account' => 'This organization does not take online payments yet. Please pay them another way.',
    ],

    'messages' => [
        'trial_started' => 'Your free trial has started. Enjoy!',
        'moved_to_free' => 'You are now on the free plan. All your data is kept.',
        'moves_to_free' => 'You keep your plan until the paid period ends. You move to the free plan on :date.',
        'plan_kept' => 'Done: you keep your plan and it renews as usual.',
        // A client's own merchant accounts (Phase 6).
        'merchant_requested' => 'Saved. The change waits for another person to approve it; everyone who can approve was told.',
        'merchant_requested_alone' => 'Saved. Nobody else can approve it here, so it takes effect by itself on :date. The owners were told.',
        'merchant_renamed' => 'Name saved.',
        'merchant_approved' => 'Approved. Customers\' payments now go to this account.',
        'merchant_rejected' => 'The change was rejected. Nothing changed.',
        'merchant_disabled' => 'Turned off. Customers cannot pay online into this account until it is turned on again.',
        'merchant_enabled' => 'Turned on. Customers can pay online again.',
        'merchant_check_ok' => 'The gateway accepted the store details.',
    ],

    'gateways' => [
        'sslcommerz' => 'SSLCommerz',
    ],

    'merchant' => [
        'takes_effect_after_approval' => 'once another person approves it',
        'takes_effect_on' => 'on :date unless someone rejects it',
        'applied_after_wait' => 'by itself, after the waiting time',
        'approved_by' => 'approved by :name',
    ],

];
