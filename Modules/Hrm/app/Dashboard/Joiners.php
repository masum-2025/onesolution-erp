<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;

/** People who joined this month, compared with last month, and the last six months. */
final class Joiners implements DashboardWidget
{
    use ReadsEmployees;

    public function data(CurrentContext $context): array
    {
        $month = $this->today($context)->startOfMonth();
        $joinedIn = fn (CarbonImmutable $start) => $this->employees($context)
            ->whereDate('joined_on', '>=', $start->toDateString())
            ->whereDate('joined_on', '<=', $start->endOfMonth()->toDateString())
            ->count();

        $series = [];
        for ($back = 5; $back >= 0; $back--) {
            $series[] = $joinedIn($month->subMonthsNoOverflow($back));
        }

        return WidgetData::stat(
            value: $series[5],
            hint: __('hrm::dashboard.joiners_hint'),
            change: $series[5] - $series[4],
            series: $series,
        );
    }
}
