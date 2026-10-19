<?php

namespace Modules\Leave\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Attendance\Services\Days;

/**
 * Which days are not working days for an employee's unit, so leave does
 * not count them: weekends and holidays as Attendance keeps them (its
 * public Days service), when Attendance is on for the company. Without
 * Attendance every day is a working day. Answers are remembered only
 * inside one returned function (never across requests).
 */
class WorkCalendar
{
    public function __construct(private ModuleResolver $modules) {}

    /**
     * @return callable(CarbonImmutable): bool
     */
    public function offDays(Organization $company, Organization $unit): callable
    {
        if (! class_exists(Days::class) || ! $this->modules->isEnabled('attendance', $company)) {
            return fn (CarbonImmutable $day) => false;
        }
        $days = app(Days::class);
        $known = [];

        return function (CarbonImmutable $day) use ($company, $unit, $days, &$known) {
            return $known[$day->toDateString()] ??= $days->dayOff($company, $unit, $day) !== null;
        };
    }
}
