<?php

namespace Modules\Leave\Payroll;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Leave\Models\LeaveEncashment;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\Place;
use Modules\Payroll\Contracts\DaysToPay;

/**
 * Leave paid out, for Payroll (its DaysToPay contract, tagged
 * "payroll.days_to_pay"): approved pay-outs whose approval day falls in the
 * run's days, as half days per employee (one line per kind of leave).
 * Payroll works the money out. Nothing while Leave is off.
 */
class EncashedDays implements DaysToPay
{
    public function __construct(private ModuleResolver $modules, private Place $place) {}

    public function daysToPay(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($employeeIds === [] || ! $this->modules->isEnabled('leave', $company)) {
            return [];
        }
        $rows = $this->place->query(LeaveEncashment::class, $company)->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveEncashment::APPROVED)
            ->where('pay_on', '>=', $from->toDateString())->where('pay_on', '<=', $to->toDateString())->get();
        $types = $this->place->query(LeaveType::class, $company)->whereKey($rows->pluck('type_id')->unique()->values()->all())->get()->keyBy(fn (LeaveType $type) => $type->getKey());

        $lines = [];
        foreach ($rows->groupBy(fn (LeaveEncashment $row) => $row->employee_id.'|'.$row->type_id) as $group) {
            $first = $group->first();
            $type = $types[$first->type_id] ?? null;
            $names = $type?->texts('name') ?? [];
            $lines[$first->employee_id][] = [
                'code' => 'LEAVE_PAID_OUT',
                // The slip line in each language the app speaks: "Leave paid out (Earned leave)".
                'name' => collect(LanguageRegistry::codes())->mapWithKeys(fn (string $locale) => [
                    $locale => __('leave::leave.encash.slip_line', ['type' => $names[$locale] ?? $names['en'] ?? $type?->code ?? ''], $locale),
                ])->all(),
                'halves' => (int) $group->sum('halves'),
                'taxable' => true,
            ];
        }

        return $lines;
    }
}
