<?php

namespace Modules\Attendance\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Models\Correction;
use Modules\Attendance\Services\Workplace;

/**
 * Corrections waiting for a decision at the unit (and below it): a count on
 * the dashboard, and in the bell for people who decide them (not their own).
 */
final class CorrectionsWaiting implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        return WidgetData::stat($this->waiting($context)?->count() ?? 0, hint: __('attendance::dashboard.corrections_hint'));
    }

    public function items(CurrentContext $context): array
    {
        if ($context->organization()->type === OrganizationType::Group || ! Gate::allows('attendance.correct', $context->organization())) {
            return [];
        }
        $count = $this->waiting($context)?->where('requested_by', '!=', $context->user()?->getKey())->count() ?? 0;

        return $count === 0 ? [] : [new AttentionItem('attendance.corrections_waiting', __('attendance::dashboard.attention'), $count, '/attendance/corrections', 'warn')];
    }

    /**
     * @return Builder<Correction>|null
     */
    private function waiting(CurrentContext $context): ?Builder
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return null;
        }
        $workplace = app(Workplace::class);

        return $workplace->query(Correction::class, $workplace->companyOf($unit))
            ->whereIn('unit_id', $workplace->subtreeIds($unit))->where('status', Correction::PENDING);
    }
}
