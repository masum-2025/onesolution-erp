<?php

namespace Modules\Patients\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\MergeRequest;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientsStore;

/**
 * Patients on the dashboard and in the bell: patients registered today at the
 * unit and below; merge requests waiting for someone else's decision.
 */
final class PatientsWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $store = app(PatientsStore::class);
        $today = $store->today($company);
        $count = $store->query(Patient::class, $company)->whereIn('unit_id', $store->subtreeIds($context->organization()))
            ->where('created_at', '>=', $today->utc())->count();

        return WidgetData::stat($count);
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context);
        if ($company === null || ! Gate::allows('patients.manage', $context->organization())) {
            return [];
        }
        $waiting = app(PatientsStore::class)->query(MergeRequest::class, $company)->where('status', MergeRequest::PENDING)
            ->where('requested_by', '!=', $context->user()?->getKey())->count();

        return $waiting > 0 ? [new AttentionItem('patients.merges', __('patients::dashboard.attention_merges'), $waiting, '/patients/merges', 'warn')] : [];
    }

    private function company(CurrentContext $context): ?Organization
    {
        $unit = $context->organization();

        return $unit->type === OrganizationType::Group ? null : app(PatientsStore::class)->companyOf($unit);
    }
}
