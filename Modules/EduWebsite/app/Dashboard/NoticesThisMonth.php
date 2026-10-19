<?php

namespace Modules\EduWebsite\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Services\Place;

/** Notices the website shows that are dated this month (company calendar). */
final class NoticesThisMonth implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $today = $place->today($company);
        $count = $place->query(Notice::class, $company)->where('is_published', true)
            ->whereBetween('published_on', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->count();

        return WidgetData::stat($count, hint: __('edu_website::dashboard.notices_hint'));
    }
}
