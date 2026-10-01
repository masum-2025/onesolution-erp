<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Hrm\Enums\EmploymentEventType;

/**
 * An employment step happened (manifest events "hrm.employee.*"). Other
 * modules (Attendance, Payroll) listen to this instead of reading HRM's
 * tables. Ids and dates only: no personal details.
 */
class EmploymentChanged
{
    use Dispatchable;

    public function __construct(
        public EmploymentEventType $type,
        public string $employeeId,
        public string $organizationId,
        public string $companyId,
        public string $effectiveOn,
        public ?string $eventId = null,
    ) {}

    /** "hrm.employee.hired" and so on. */
    public function name(): string
    {
        return 'hrm.employee.'.$this->type->value;
    }
}
