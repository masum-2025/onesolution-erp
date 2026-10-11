<?php

namespace Modules\EducationFees\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money was taken for a student (a receipt): reminders stop, the books record it.
 * Ids only.
 */
class FeePaid
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $receiptId, public string $studentId) {}
}
