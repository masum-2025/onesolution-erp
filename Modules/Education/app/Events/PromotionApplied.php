<?php

namespace Modules\Education\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A promotion list was applied or undone: enrollments of a whole level moved (each also as EnrollmentChanged). Ids only.
 */
class PromotionApplied
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $batchId) {}
}
