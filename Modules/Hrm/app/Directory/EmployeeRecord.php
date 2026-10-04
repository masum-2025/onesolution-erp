<?php

namespace Modules\Hrm\Directory;

use Carbon\CarbonImmutable;

/**
 * What other modules may know about an employee (Attendance, Payroll): who,
 * where they work, whether they are employed on a day. Never ids from
 * documents, pay or contact details.
 */
final readonly class EmployeeRecord
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $unitId,
        public string $code,
        public string $name,
        public string $status,
        public ?string $userId,
        public CarbonImmutable $joinedOn,
        public ?CarbonImmutable $exitsOn,
    ) {}

    /** Employed on that day: joined by then, not gone before it. */
    public function employedOn(CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return $this->joinedOn->toDateString() <= $date
            && ($this->exitsOn === null || $this->exitsOn->toDateString() >= $date);
    }
}
