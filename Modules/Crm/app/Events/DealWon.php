<?php

namespace Modules\Crm\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A deal reached its pipeline's won stage (dispatched once the change is
 * saved). Other modules act on it (Education makes an application from an
 * "admissions" deal) and read the contact through Customers::contact().
 * Ids and the pipeline's key only.
 */
class DealWon
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $unitId,
        public string $dealId,
        public string $contactId,
        public string $pipelineKey,
        public ?string $actorId,
    ) {}
}
