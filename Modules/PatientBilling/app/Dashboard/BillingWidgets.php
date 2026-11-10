<?php

namespace Modules\PatientBilling\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\PatientBilling\Models\CashDay;
use Modules\PatientBilling\Models\DiscountRequest;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Services\PatientBillingStore;

/**
 * Patient billing on the dashboard and in the bell: money taken today at the
 * unit and below (refunds taken off); discounts waiting for someone else's
 * approval; cash days waiting for a supervisor.
 */
final class BillingWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $store = app(PatientBillingStore::class);
        $rows = $store->query(Payment::class, $company)->whereIn('unit_id', $store->subtreeIds($context->organization()))
            ->where('received_at', '>=', $store->today($company)->utc())->where('method', '!=', 'advance')->get(['kind', 'amount_minor']);
        $net = (int) $rows->where('kind', '!=', Payment::REFUND)->sum('amount_minor') - (int) $rows->where('kind', Payment::REFUND)->sum('amount_minor');

        return WidgetData::stat($net, format: 'money', currency: rescue(fn () => $store->currency($company), null, false));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return [];
        }
        $store = app(PatientBillingStore::class);
        $unit = $context->organization();
        $me = $context->user()?->getKey();
        $items = [];
        if (Gate::allows('patient_billing.approve', $unit)) {
            $waiting = $store->query(DiscountRequest::class, $company)->where('status', DiscountRequest::PENDING)->where('requested_by', '!=', $me)->count();
            if ($waiting > 0) {
                $items[] = new AttentionItem('patient_billing.discounts', __('patient_billing::dashboard.attention_discounts'), $waiting, '/patient-billing/approvals', 'warn');
            }
        }
        if (Gate::allows('patient_billing.close_day', $unit)) {
            $days = $store->query(CashDay::class, $company)->whereIn('unit_id', $store->subtreeIds($unit))->where('status', CashDay::REVIEW)->where('user_id', '!=', $me)->count();
            if ($days > 0) {
                $items[] = new AttentionItem('patient_billing.cash_days', __('patient_billing::dashboard.attention_days'), $days, '/patient-billing/cash', 'warn');
            }
        }

        return $items;
    }

    private function company(CurrentContext $context): ?Organization
    {
        $unit = $context->organization();

        return $unit->type === OrganizationType::Group ? null : app(PatientBillingStore::class)->companyOf($unit);
    }
}
