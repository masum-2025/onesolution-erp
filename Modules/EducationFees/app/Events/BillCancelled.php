<?php

namespace Modules\EducationFees\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bill was cancelled: nothing more is owed on it.
 * Ids only.
 */
class BillCancelled
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $billId, public string $studentId) {}
}
