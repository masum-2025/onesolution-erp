<?php

namespace Modules\Leave\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Leave errors: a translated message (leave::leave.errors.*) that says what
 * to do next, and a stable code.
 */
class LeaveException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'leave::leave.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function employeeNotFound(): self
    {
        return new self('employee_not_found', 404);
    }

    public static function typeNotFound(): self
    {
        return new self('type_not_found', 404);
    }

    public static function requestNotFound(): self
    {
        return new self('request_not_found', 404);
    }

    public static function notLinked(): self
    {
        return new self('not_linked', 409);
    }

    public static function typeClosed(): self
    {
        return new self('type_closed', 422);
    }

    public static function notEmployed(): self
    {
        return new self('not_employed', 422);
    }

    public static function notEnough(int $availableHalves): self
    {
        $halves = max(0, $availableHalves);
        $days = intdiv($halves, 2).($halves % 2 === 0 ? '' : '.5');

        return new self('not_enough', 422, ['days' => $days], ['available_halves' => $availableHalves]);
    }

    public static function overlaps(): self
    {
        return new self('overlaps', 409);
    }

    public static function crossesYear(): self
    {
        return new self('crosses_year', 422);
    }

    public static function noWorkingDays(): self
    {
        return new self('no_working_days', 422);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function decided(): self
    {
        return new self('decided', 409);
    }

    public static function ownRequest(): self
    {
        return new self('own_request', 403);
    }

    public static function notYourStep(): self
    {
        return new self('not_your_step', 403);
    }

    public static function cannotCancel(): self
    {
        return new self('cannot_cancel', 409);
    }

    public static function codeTaken(): self
    {
        return new self('code_taken', 409);
    }
}
