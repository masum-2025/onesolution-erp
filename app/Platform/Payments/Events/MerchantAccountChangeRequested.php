<?php

namespace App\Platform\Payments\Events;

use App\Models\User;
use App\Platform\Payments\Models\MerchantAccount;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone asked to connect or change where a client's customers' money
 * goes. Everyone who could approve it is told at once. After commit.
 */
class MerchantAccountChangeRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public MerchantAccount $account, public User $actor) {}
}
