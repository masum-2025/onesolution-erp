<?php

namespace Modules\Attendance\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Models\AbsenceAlert;
use Modules\Attendance\Services\Workplace;

/**
 * In the bell of people who manage attendance: how many people at the unit
 * (and below it) were reported absent many days in a row this past week
 * (attendance:absence-alerts).
 */
final class AbsentInARow implements AttentionProvider
{
    public const DAYS_SHOWN = 7;

    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group || ! Gate::allows('attendance.manage', $unit)) {
            return [];
        }
        $workplace = app(Workplace::class);
        $company = $workplace->companyOf($unit);
        $count = $workplace->query(AbsenceAlert::class, $company)->whereIn('unit_id', $workplace->subtreeIds($unit))
            ->where('sent_on', '>=', $workplace->today($company)->subDays(self::DAYS_SHOWN)->toDateString())
            ->distinct()->count('employee_id');

        return $count === 0 ? [] : [new AttentionItem('attendance.absent_in_a_row', __('attendance::dashboard.absent_in_a_row'), $count, '/attendance/month', 'bad')];
    }
}
