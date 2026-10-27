<?php

namespace Modules\Recruitment\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Recruitment\Models\Feedback;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Services\Place;

/** In the bell of an interviewer: interviews that have taken place without their feedback yet. */
final class FeedbackOwed implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $held = $place->query(Interview::class, $company)->whereJsonContains('interviewer_ids', $user->getKey())
            ->where('status', Interview::SCHEDULED)->where('starts_at', '<=', now())->limit(100)->get();
        if ($held->isEmpty()) {
            return [];
        }
        $given = $place->query(Feedback::class, $company)->whereIn('interview_id', $held->map(fn (Interview $interview) => $interview->getKey())->all())
            ->where('user_id', $user->getKey())->pluck('interview_id')->all();
        $count = $held->reject(fn (Interview $interview) => in_array($interview->getKey(), $given, true))->count();

        return $count === 0 ? [] : [new AttentionItem('recruitment.feedback_owed', __('recruitment::dashboard.attention_feedback'), $count, '/recruitment/interviews', 'warn')];
    }
}
