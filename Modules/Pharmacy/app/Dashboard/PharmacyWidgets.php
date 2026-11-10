<?php

namespace Modules\Pharmacy\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Models\SupplierReturn;
use Modules\Pharmacy\Services\PharmacyStock;
use Modules\Pharmacy\Services\PharmacyStore;

/**
 * The pharmacy on the dashboard and in the bell, for the unit in the context
 * and below: batches expiring soon (rule pharmacy.near_expiry_days) or already
 * expired; supplier returns waiting for someone else's approval.
 */
final class PharmacyWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $counts = app(PharmacyStock::class)->expiryCounts($company, $context->organization());

        return WidgetData::stat($counts['near'] + $counts['expired'], hint: __('pharmacy::dashboard.expiring_hint', ['expired' => $counts['expired']]));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return [];
        }
        $unit = $context->organization();
        $items = [];
        if (Gate::allows('pharmacy.view', $unit)) {
            $counts = app(PharmacyStock::class)->expiryCounts($company, $unit);
            if ($counts['expired'] > 0) {
                $items[] = new AttentionItem('pharmacy.expired', __('pharmacy::dashboard.attention_expired'), $counts['expired'], '/pharmacy/stock?show=expired', 'warn');
            }
        }
        if (Gate::allows('pharmacy.approve', $unit)) {
            $store = app(PharmacyStore::class);
            $waiting = $store->query(SupplierReturn::class, $company)->whereIn('unit_id', $store->subtreeIds($unit))->where('status', SupplierReturn::SUBMITTED)
                ->where('created_by', '!=', $context->user()?->getKey())->count();
            if ($waiting > 0) {
                $items[] = new AttentionItem('pharmacy.returns', __('pharmacy::dashboard.attention_returns'), $waiting, '/pharmacy/returns?status=submitted', 'warn');
            }
        }

        return $items;
    }

    private function company(CurrentContext $context): ?Organization
    {
        $unit = $context->organization();

        return $unit->type === OrganizationType::Group ? null : app(PharmacyStore::class)->companyOf($unit);
    }
}
