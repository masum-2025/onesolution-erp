<?php

namespace Modules\EducationFees\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A receipt was voided: what it paid is owed again.
 * Ids only.
 */
class ReceiptVoided
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $receiptId, public string $studentId) {}
}
