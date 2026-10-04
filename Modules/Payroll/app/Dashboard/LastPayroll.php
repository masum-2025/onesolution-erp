<?php

namespace Modules\Payroll\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Services\Payrolls;

/** The latest approved or paid month: net pay of how many people. */
final class LastPayroll implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        $run = in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)
            ? app(Payrolls::class)->query(Run::class, $unit)->whereIn('status', [Run::APPROVED, Run::PAID])->orderByDesc('period')->first()
            : null;

        return $run === null
            ? WidgetData::stat(0, hint: __('payroll::dashboard.none_yet'))
            : WidgetData::stat($run->net_minor, hint: __('payroll::dashboard.last_hint', ['period' => $run->period, 'count' => $run->employees]), format: 'money', currency: $run->currency_code);
    }
}
