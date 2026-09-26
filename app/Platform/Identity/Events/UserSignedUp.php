<?php

namespace App\Platform\Identity\Events;

use App\Models\User;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone created their own account (self-serve), with a verified email or
 * phone. After commit.
 */
class UserSignedUp implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public User $user, public Partner $partner, public string $channel) {}
}
