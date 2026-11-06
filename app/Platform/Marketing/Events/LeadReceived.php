<?php

namespace App\Platform\Marketing\Events;

use App\Platform\Marketing\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visitor wrote through a company website's contact form. The partner's
 * staff are told (queued); a CRM can listen to make its own lead.
 */
class LeadReceived
{
    use Dispatchable;

    public function __construct(public Lead $lead) {}
}
