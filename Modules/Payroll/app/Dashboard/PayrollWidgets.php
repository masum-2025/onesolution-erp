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
use Modules\Payroll\Models\BonusRun;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunApproval;
use Modules\Payroll\Models\Settlement;
use Modules\Payroll\Services\Payrolls;
use Modules\Payroll\Services\Settlements;

/**
 * Payroll waiting for approval: a count on the dashboard, and in the bell
 * for approvers who did not open, send or already approve it themselves;
 * loans, festival bonuses and final settlements likewise; payroll staff
 * hear of people who left without a final settlement.
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
        if ($company === null) {
            return [];
        }
        $due = Gate::allows('payroll.run', $company) ? count(app(Settlements::class)->due($company)) : 0;
        $dueItem = $due === 0 ? [] : [new AttentionItem('payroll.settlements_due', __('payroll::dashboard.attention_settlements_due'), $due, '/payroll/settlements', 'info')];
        if (! Gate::allows('payroll.approve', $company)) {
            return $dueItem;
        }
        $me = $context->user()?->getKey();
        $payrolls = app(Payrolls::class);
        $approved = $payrolls->query(RunApproval::class, $company)->where('user_id', $me)->pluck('run_id')->all();
        $count = $payrolls->query(Run::class, $company)->where('status', Run::PENDING)->whereNotIn('id', $approved)
            ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $me))->count();

        $loans = $payrolls->query(Loan::class, $company)->where('status', Loan::PENDING)
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $me))->count();
        $bonuses = $payrolls->query(BonusRun::class, $company)->where('status', BonusRun::PENDING)
            ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $me))->count();

        $settlements = $payrolls->query(Settlement::class, $company)->where('status', Settlement::PENDING)
            ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $me))->count();

        return array_values(array_filter([
            ...$dueItem,
            $settlements === 0 ? null : new AttentionItem('payroll.settlements_waiting', __('payroll::dashboard.attention_settlements'), $settlements, '/payroll/settlements', 'warn'),
            $count === 0 ? null : new AttentionItem('payroll.runs_waiting', __('payroll::dashboard.attention'), $count, '/payroll', 'warn'),
            $loans === 0 ? null : new AttentionItem('payroll.loans_waiting', __('payroll::dashboard.attention_loans'), $loans, '/payroll/loans', 'warn'),
            $bonuses === 0 ? null : new AttentionItem('payroll.bonuses_waiting', __('payroll::dashboard.attention_bonuses'), $bonuses, '/payroll/bonuses', 'warn'),
        ]));
    }

    private function company(CurrentContext $context): ?Organization
    {
        $unit = $context->organization();

        return in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true) ? $unit : null;
    }
}
