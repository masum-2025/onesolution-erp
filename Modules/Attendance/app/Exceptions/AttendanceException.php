<?php

namespace Modules\Attendance\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Attendance errors: a translated message (attendance::attendance.errors.*)
 * that says what to do next, and a stable code.
 */
class AttendanceException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'attendance::attendance.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function employeeNotFound(): self
    {
        return new self('employee_not_found', 404);
    }

    public static function shiftNotFound(): self
    {
        return new self('shift_not_found', 404);
    }

    public static function holidayNotFound(): self
    {
        return new self('holiday_not_found', 404);
    }

    public static function punchNotFound(): self
    {
        return new self('punch_not_found', 404);
    }

    public static function correctionNotFound(): self
    {
        return new self('correction_not_found', 404);
    }

    public static function notLinked(): self
    {
        return new self('not_linked', 409);
    }

    public static function selfPunchOff(): self
    {
        return new self('self_punch_off', 403);
    }

    public static function locationNeeded(): self
    {
        return new self('location_needed', 409);
    }

    public static function notEmployed(): self
    {
        return new self('not_employed', 409);
    }

    public static function punchVoided(): self
    {
        return new self('punch_voided', 409);
    }

    public static function correctionDecided(): self
    {
        return new self('correction_decided', 409);
    }

    public static function ownCorrection(): self
    {
        return new self('own_correction', 403);
    }

    public static function unknownStep(): self
    {
        return new self('unknown_step', 404);
    }

    public static function rangeTooLong(int $days): self
    {
        return new self('range_too_long', 422, ['days' => (string) $days]);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function locationNotFound(): self
    {
        return new self('location_not_found', 404);
    }

    public static function noWorkplaces(): self
    {
        return new self('no_workplaces', 409);
    }

    public static function locationVague(int $accuracy, int $max): self
    {
        return new self('location_vague', 422, ['accuracy' => (string) $accuracy, 'max' => (string) $max]);
    }

    public static function outsideWorkplace(int $metres, string $name): self
    {
        return new self('outside_workplace', 403, ['metres' => (string) $metres, 'name' => $name]);
    }

    public static function offlineTooOld(int $hours): self
    {
        return new self('offline_too_old', 422, ['hours' => (string) $hours]);
    }

    public static function offlineFuture(): self
    {
        return new self('offline_future', 422);
    }
}
