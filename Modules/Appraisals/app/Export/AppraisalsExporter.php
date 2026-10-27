<?php

namespace Modules\Appraisals\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Appraisals\Models\Competency;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Goal;
use Modules\Appraisals\Models\Rating;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Models\Step;

/**
 * Appraisals in the client's data export: competencies, cycles, reviews
 * with their results, goals, ratings with comments (decrypted: the export
 * is the client's own data) and every step. Scores per mille.
 */
class AppraisalsExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'appraisals';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'competencies' => $this->rows(Competency::class, $organization, $organizationIds, fn (Competency $item) => [
                'id' => $item->getKey(), 'code' => $item->code, 'name' => json_encode($item->texts('name'), JSON_UNESCAPED_UNICODE), 'is_active' => $item->is_active,
            ]),
            'cycles' => $this->rows(Cycle::class, $organization, $organizationIds, fn (Cycle $cycle) => [
                'id' => $cycle->getKey(), 'unit_id' => $cycle->unit_id, 'name' => json_encode($cycle->texts('name'), JSON_UNESCAPED_UNICODE),
                'period_from' => $cycle->period_from?->toDateString(), 'period_to' => $cycle->period_to?->toDateString(), 'status' => $cycle->status,
                'goal_share_percent' => $cycle->goal_share_percent, 'self_review' => $cycle->self_review,
            ]),
            'reviews' => $this->rows(Review::class, $organization, $organizationIds, fn (Review $review) => [
                'id' => $review->getKey(), 'cycle_id' => $review->cycle_id, 'employee_id' => $review->employee_id, 'manager_employee_id' => $review->manager_employee_id,
                'stage' => $review->stage, 'goals_score' => $review->goals_score, 'competencies_score' => $review->competencies_score, 'score' => $review->score,
                'band' => $review->band, 'self_summary' => $review->self_summary, 'manager_summary' => $review->manager_summary,
                'employee_comment' => $review->employee_comment, 'completed_at' => $review->completed_at?->toIso8601String(),
            ]),
            'goals' => $this->rows(Goal::class, $organization, $organizationIds, fn (Goal $goal) => [
                'id' => $goal->getKey(), 'review_id' => $goal->review_id, 'title' => $goal->title, 'description' => $goal->description, 'weight' => $goal->weight,
            ]),
            'ratings' => $this->rows(Rating::class, $organization, $organizationIds, fn (Rating $rating) => [
                'review_id' => $rating->review_id, 'goal_id' => $rating->goal_id, 'competency_id' => $rating->competency_id,
                'self_rating' => $rating->self_rating, 'self_comment' => $rating->self_comment, 'manager_rating' => $rating->manager_rating, 'manager_comment' => $rating->manager_comment,
            ]),
            'steps' => $this->rows(Step::class, $organization, $organizationIds, fn (Step $step) => [
                'review_id' => $step->review_id, 'from' => $step->from_stage, 'to' => $step->to_stage, 'note' => $step->note, 'user_id' => $step->user_id,
                'at' => $step->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
     * @return iterable<array<string, mixed>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
