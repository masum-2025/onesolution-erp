<?php

namespace Modules\StudentAttendance\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\StudentAttendance\Services\Register;

/**
 * The unit in the address and its institution (the same 404 for anything
 * not visible), and days and months read from the address.
 *
 * @property Register $register
 */
trait AttendancePlace
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, $this->register->companyOf($unit)];
    }

    /** A day written YYYY-MM-DD (422 otherwise). */
    private function day(string $date, string $field = 'date'): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') : false;
        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw ValidationException::withMessages([$field => __('student_attendance::attendance.validation.date')]);
        }

        return $parsed;
    }

    /** A month written YYYY-MM: its first and last day (422 otherwise). */
    private function month(string $month): array
    {
        $first = $this->day("{$month}-01", 'month');

        return [$first, $first->endOfMonth()->startOfDay()];
    }
}
