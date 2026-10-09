<?php

namespace Modules\Education\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student left or graduated (status says which). Ids only.
 */
class StudentLeft
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $studentId, public string $status) {}
}
