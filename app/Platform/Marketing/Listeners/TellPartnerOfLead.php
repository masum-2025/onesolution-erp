<?php

namespace App\Platform\Marketing\Listeners;

use App\Platform\Marketing\Events\LeadReceived;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the partner's owners and sales staff that someone wrote, by email
 * in the partner's own brand. Only the visitor's name and the first words
 * of the message go out; the rest is read in the console.
 */
class TellPartnerOfLead implements ShouldQueue
{
    public function __construct(private Notifier $notifier, private Recipients $recipients) {}

    public function handle(LeadReceived $event): void
    {
        $lead = $event->lead->loadMissing('site.partner');
        $partner = $lead->site->partner;

        $this->notifier->notify(
            'marketing.lead_received',
            $this->recipients->partnerStaff($partner, PartnerUserRole::Owner, PartnerUserRole::Sales),
            ['visitor' => $lead->name, 'details' => mb_strimwidth((string) ($lead->message ?? ''), 0, 160, '…') ?: '—'],
            $partner,
        );
    }
}
