<?php

namespace Modules\Diagnostics\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\OrderTest;
use Modules\Diagnostics\Services\DiagnosticsStore;

/**
 * The lab on the dashboard and in the bell, at the unit and below: reports
 * past their due time; results waiting for someone else's verification.
 */
final class LabWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }

        return WidgetData::stat($this->late($company, $context->organization()), hint: __('diagnostics::dashboard.late_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return [];
        }
        $unit = $context->organization();
        $store = app(DiagnosticsStore::class);
        $items = [];
        if (Gate::allows('diagnostics.verify', $unit)) {
            $orders = $store->query(Order::class, $company)->whereIn('unit_id', $store->subtreeIds($unit))->select('id');
            $waiting = $store->query(OrderTest::class, $company)->whereIn('order_id', $orders)->where('status', OrderTest::RESULTED)
                ->where('resulted_by', '!=', $context->user()?->getKey())->count();
            if ($waiting > 0) {
                $items[] = new AttentionItem('diagnostics.verify', __('diagnostics::dashboard.attention_verify'), $waiting, '/diagnostics/orders?stage=resulted', 'warn');
            }
        }
        if (Gate::allows('diagnostics.view', $unit) && ($late = $this->late($company, $unit)) > 0) {
            $items[] = new AttentionItem('diagnostics.late', __('diagnostics::dashboard.attention_late'), $late, '/diagnostics/orders', 'bad');
        }

        return $items;
    }

    private function late(Organization $company, Organization $unit): int
    {
        $store = app(DiagnosticsStore::class);

        return $store->query(Order::class, $company)->whereIn('unit_id', $store->subtreeIds($unit))->whereIn('status', ['collected', 'resulted'])
            ->where('due_at', '<', CarbonImmutable::now())->count();
    }

    private function company(CurrentContext $context): ?Organization
    {
        $unit = $context->organization();

        return $unit->type === OrganizationType::Group ? null : app(DiagnosticsStore::class)->companyOf($unit);
    }
}
