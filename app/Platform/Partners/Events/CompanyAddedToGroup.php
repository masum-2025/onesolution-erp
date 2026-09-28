<?php

namespace App\Platform\Partners\Events;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The partner added a company to a client's existing group: the group's
 * owners are told (it is billed with the group). After commit.
 */
class CompanyAddedToGroup implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Organization $group, public Organization $company, public Partner $partner) {}
}
