<?php

namespace Modules\Recruitment\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Services\Place;
use Modules\Recruitment\Services\Stages;

/**
 * Applications still at the first stage (confirmed, nobody has looked at
 * them yet) at the unit and below: a count on the dashboard, and in the
 * bell of the people who manage recruitment there.
 */
final class NewApplications implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();

        return WidgetData::stat($unit->type === OrganizationType::Group ? 0 : $this->count($unit), hint: __('recruitment::dashboard.new_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null || $unit->type === OrganizationType::Group || ! Gate::allows('recruitment.manage', $unit)) {
            return [];
        }
        $count = $this->count($unit);

        return $count === 0 ? [] : [new AttentionItem('recruitment.new_applications', __('recruitment::dashboard.attention_new'), $count, '/recruitment/openings', 'info')];
    }

    private function count(Organization $unit): int
    {
        $place = app(Place::class);
        $company = $place->companyOf($unit);

        return $place->query(Application::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))
            ->where('status', Application::ACTIVE)->where('stage', app(Stages::class)->first($company))->count();
    }
}
