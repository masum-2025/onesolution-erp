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
use Modules\Hrm\Directory\EmployeeDirectory;

/** In the bell of an employee: their own review waiting for them (goals to send, a self review, the result to acknowledge). */
final class MyAppraisal implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || ! Gate::allows('appraisals.self', $unit)) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $me = app(EmployeeDirectory::class)->forUser($company, $user);
        if ($me === null) {
            return [];
        }
        $open = $place->query(Cycle::class, $company)->where('status', Cycle::OPEN)->pluck('id')->all();
        $count = $place->query(Review::class, $company)->whereIn('cycle_id', $open)->where('employee_id', $me->id)
            ->where(fn ($query) => $query->whereIn('stage', [Review::SELF, Review::ACKNOWLEDGE])
                ->orWhere(fn ($inner) => $inner->where('stage', Review::GOALS)->whereNull('goals_submitted_at')))->count();

        return $count === 0 ? [] : [new AttentionItem('appraisals.my_review', __('appraisals::dashboard.attention_mine'), $count, '/appraisals/me', 'warn')];
    }
}
