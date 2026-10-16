<?php

namespace Modules\TeacherFeedback\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\TeacherFeedback\Models\Comment;
use Modules\TeacherFeedback\Models\Rater;
use Modules\TeacherFeedback\Models\Round;
use Modules\TeacherFeedback\Services\FeedbackOffice;

/**
 * Ratings received in the rounds open now at the unit; in the bell,
 * comments waiting for a decision for who manages feedback.
 */
final class FeedbackWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = $this->place($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$company, $units] = $place;
        $office = app(FeedbackOffice::class);
        $open = $office->query(Round::class, $company)->whereIn('unit_id', $units)->where('status', 'open')->pluck('id');

        return WidgetData::stat($office->query(Rater::class, $company)->whereIn('round_id', $open)->count(), hint: trans_choice('teacher_feedback::dashboard.responses_hint', $open->count(), ['count' => $open->count()]));
    }

    public function items(CurrentContext $context): array
    {
        $place = $this->place($context);
        if ($place === null || ! Gate::allows('teacher_feedback.manage', $context->organization())) {
            return [];
        }
        [$company, $units] = $place;
        $office = app(FeedbackOffice::class);
        $rounds = $office->query(Round::class, $company)->whereIn('unit_id', $units)->pluck('id');
        $waiting = $office->query(Comment::class, $company)->whereIn('round_id', $rounds)->where('status', 'pending')->count();

        return $waiting === 0 ? [] : [new AttentionItem('teacher_feedback.comments', __('teacher_feedback::dashboard.attention_comments'), $waiting, '/teacher-feedback', 'info')];
    }

    /** @return array{0: Organization, 1: list<string>}|null */
    private function place(CurrentContext $context): ?array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group || $unit->type === OrganizationType::Department) {
            return null;
        }
        $office = app(FeedbackOffice::class);

        return [$office->companyOf($unit), $office->subtreeIds($unit)];
    }
}
