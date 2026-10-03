<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Hrm\Enums\EmployeeStatus;

/** People employed now, the change over 30 days and the last six month-ends. */
final class Headcount implements DashboardWidget
{
    use ReadsEmployees;

    public function data(CurrentContext $context): array
    {
        $today = $this->today($context);
        $now = $this->employees($context)->where('status', '!=', EmployeeStatus::Exited->value)->count();

        $series = [];
        for ($back = 5; $back >= 1; $back--) {
            $series[] = $this->employedOn($context, $today->subMonthsNoOverflow($back)->endOfMonth()->startOfDay());
        }
        $series[] = $now;

        return WidgetData::stat(
            value: $now,
            hint: __('hrm::dashboard.headcount_hint'),
            change: $now - $this->employedOn($context, $today->subDays(30)),
            series: $series,
        );
    }
}
