<?php

namespace App\Platform\Marketing;

use App\Platform\Marketing\Events\LeadReceived;
use App\Platform\Marketing\Listeners\TellPartnerOfLead;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Company websites (MKT): what happens around them. */
class MarketingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Someone wrote through a website: the partner's staff are told (queued).
        Event::listen(LeadReceived::class, TellPartnerOfLead::class);
    }
}
