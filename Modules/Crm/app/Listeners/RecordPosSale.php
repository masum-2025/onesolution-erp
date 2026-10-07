<?php

namespace Modules\Crm\Listeners;

use App\Platform\Tenancy\Models\Organization;
use Modules\Crm\Services\Customers;
use Modules\Pos\Events\SaleMade;

/**
 * A sale or return at a counter with a customer: added to what the contact
 * spent and to their points (once per sale). Nothing when CRM is off there.
 */
class RecordPosSale
{
    public function __construct(private Customers $customers) {}

    public function handle(SaleMade $event): void
    {
        if ($event->customerId === null) {
            return;
        }
        $company = Organization::query()->find($event->companyId);
        if ($company === null) {
            return;
        }
        $this->customers->recordSale($company, $event->customerId, 'pos', $event->kind, $event->saleId, $event->totalMinor, $event->soldOn, $event->currency);
    }
}
