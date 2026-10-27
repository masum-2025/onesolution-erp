<?php

namespace Modules\Appraisals\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Place;

/** In the bell of a manager: the reviews of their people waiting for them (goals to approve, a manager review). */
final class TeamAppraisals implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || ! Gate::allows('appraisals.review', $unit)) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $open = $place->query(Cycle::class, $company)->where('status', Cycle::OPEN)->pluck('id')->all();
        $count = $place->query(Review::class, $company)->whereIn('cycle_id', $open)->where('manager_user_id', $user->getKey())
            ->where(fn ($query) => $query->where('stage', Review::MANAGER)
                ->orWhere(fn ($inner) => $inner->where('stage', Review::GOALS)->whereNotNull('goals_submitted_at')))->count();

        return $count === 0 ? [] : [new AttentionItem('appraisals.team_reviews', __('appraisals::dashboard.attention_team'), $count, '/appraisals/team', 'warn')];
    }
}
