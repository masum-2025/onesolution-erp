<?php

namespace Modules\Attendance\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Attendance\Models\AttendanceDay;
use Modules\Attendance\Services\Workplace;

/** People of the unit (and below it) who checked in today, and how many of them late. */
final class CheckedInToday implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $workplace = app(Workplace::class);
        $company = $workplace->companyOf($unit);
        $days = $workplace->query(AttendanceDay::class, $company)->whereIn('unit_id', $workplace->subtreeIds($unit))
            ->where('work_date', $workplace->today($company)->toDateString())->whereNotNull('first_in_at')->get(['status']);
        $late = $days->filter(fn (AttendanceDay $day) => in_array($day->status->value, ['late', 'half_day'], true))->count();

        return WidgetData::stat($days->count(), hint: __('attendance::dashboard.checked_in_hint', ['late' => $late]));
    }
}
