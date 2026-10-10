<?php

namespace Modules\EducationFees\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student's bill was issued (a run made final, or billed on admission): collections and reminders follow it.
 * Ids only.
 */
class BillIssued
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $billId, public string $studentId) {}
}
