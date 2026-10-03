<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Hrm\Enums\EmployeeStatus;

/** People on probation, and how many of those probations end within 30 days. */
final class OnProbation implements DashboardWidget
{
    use ReadsEmployees;

    public function data(CurrentContext $context): array
    {
        $today = $this->today($context);
        $onProbation = fn () => $this->employees($context)->where('status', EmployeeStatus::Probation->value);
        $endingSoon = $onProbation()
            ->whereDate('probation_ends_on', '>=', $today->toDateString())
            ->whereDate('probation_ends_on', '<=', $today->addDays(30)->toDateString())
            ->count();

        return WidgetData::stat(
            value: $onProbation()->count(),
            hint: trans_choice('hrm::dashboard.probation_hint', $endingSoon, ['count' => $endingSoon]),
        );
    }
}
