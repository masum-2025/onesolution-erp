<?php

namespace App\Platform\Payments\Events;

use App\Models\User;
use App\Platform\Payments\Models\MerchantAccount;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A merchant account change took effect: approved by a second person, or
 * by itself after the single-approver wait ($approver null). After commit.
 */
class MerchantAccountChangeApplied implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public MerchantAccount $account, public ?User $approver) {}
}
