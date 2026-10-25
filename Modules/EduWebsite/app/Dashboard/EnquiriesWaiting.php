<?php

namespace Modules\EduWebsite\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\EduWebsite\Models\Enquiry;
use Modules\EduWebsite\Services\Place;

/**
 * Admission enquiries from the website nobody has handled yet: a count on
 * the dashboard, and in the bell of the people who handle them.
 */
final class EnquiriesWaiting implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $company = $this->company($context->organization());

        return WidgetData::stat($company === null ? 0 : $this->waiting($company), hint: __('edu_website::dashboard.enquiries_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $company = $this->company($context->organization());
        if ($context->user() === null || $company === null || ! Gate::allows('edu_website.enquiries', $company)) {
            return [];
        }
        $count = $this->waiting($company);

        return $count === 0 ? [] : [new AttentionItem('edu_website.enquiries_waiting', __('edu_website::dashboard.enquiries_attention'), $count, '/website/enquiries', 'warn')];
    }

    private function company(Organization $unit): ?Organization
    {
        return $unit->type === OrganizationType::Group ? null : app(Place::class)->companyOf($unit);
    }

    private function waiting(Organization $company): int
    {
        return app(Place::class)->query(Enquiry::class, $company)->where('status', 'new')->count();
    }
}
