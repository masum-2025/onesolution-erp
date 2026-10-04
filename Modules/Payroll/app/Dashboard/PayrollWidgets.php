<?php

namespace Modules\Payroll\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunApproval;
use Modules\Payroll\Services\Payrolls;

/**
 * Payroll waiting for approval: a count on the dashboard, and in the bell
 * for approvers who did not open, send or already approve it themselves.
 */
final class PayrollWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        $count = $company === null ? 0 : app(Payrolls::class)->query(Run::class, $company)->where('status', Run::PENDING)->count();

        return WidgetData::stat($count, hint: __('payroll::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null || ! Gate::allows('payroll.approve', $company)) {
            return [];
        }
        $me = $context->user()?->getKey();
        $payrolls = app(Payrolls::class);
        $approved = $payrolls->query(RunApproval::class, $company)->where('user_id', $me)->pluck('run_id')->all();
        $count = $payrolls->query(Run::class, $company)->where('status', Run::PENDING)->whereNotIn('id', $approved)
            ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $me))->count();

        return $count === 0 ? [] : [new AttentionItem('payroll.runs_waiting', __('payroll::dashboard.attention'), $count, '/payroll', 'warn')];
    }

    private function company(CurrentContext $context): ?Organization
    {
        $unit = $context->organization();

        return in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true) ? $unit : null;
    }
}
