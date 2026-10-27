<?php

namespace Modules\Appraisals\Http;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Appraisals\Models\Competency;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Goal;
use Modules\Appraisals\Models\Rating;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Models\Step;
use Modules\Appraisals\Services\Place;
use Modules\Appraisals\Services\Reviews;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Appraisal records as the API shows them (scores per mille). Who sees
 * what of a review: the employee their own ratings, and the manager's and
 * the result once the manager is done; the reviewer the employee's own
 * ratings once sent; people managing or viewing appraisals everything.
 */
class AppraisalsPresenter
{
    public function __construct(
        private Place $place,
        private Reviews $reviews,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function competency(Competency $competency): array
    {
        return [
            'id' => $competency->getKey(), 'code' => $competency->code, 'name' => $competency->textIn('name'), 'names' => $competency->texts('name'),
            'is_active' => $competency->is_active, 'version' => $competency->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cycle(Cycle $cycle): array
    {
        return [
            'id' => $cycle->getKey(), 'name' => $cycle->textIn('name'), 'names' => $cycle->texts('name'), 'unit_id' => $cycle->unit_id,
            'unit_name' => Organization::query()->find($cycle->unit_id)?->displayName(app()->getLocale()) ?? '',
            'period_from' => $cycle->period_from?->toDateString(), 'period_to' => $cycle->period_to?->toDateString(),
            'goals_due' => $cycle->goals_due?->toDateString(), 'self_due' => $cycle->self_due?->toDateString(), 'manager_due' => $cycle->manager_due?->toDateString(),
            'status' => $cycle->status, 'self_review' => $cycle->self_review, 'goal_share_percent' => $cycle->goal_share_percent, 'version' => $cycle->version,
        ];
    }

    /**
     * Cycles with how many reviews are at each step.
     *
     * @param  iterable<Cycle>  $cycles
     * @return list<array<string, mixed>>
     */
    public function cycles(Organization $company, iterable $cycles): array
    {
        $cycles = collect($cycles);
        $counts = $this->place->query(Review::class, $company)->whereIn('cycle_id', $cycles->map(fn (Cycle $cycle) => $cycle->getKey())->all())
            ->get(['cycle_id', 'stage'])->groupBy('cycle_id')->map(fn (Collection $rows) => $rows->countBy('stage')->all());

        return $cycles->map(fn (Cycle $cycle) => [...$this->cycle($cycle), 'stage_counts' => (object) ($counts[$cycle->getKey()] ?? []), 'reviews' => array_sum($counts[$cycle->getKey()] ?? [])])->values()->all();
    }

    /**
     * Reviews for a list: the employee, their reviewer, the step and its last day, the result (to those who may see it).
     *
     * @param  iterable<Review>  $reviews
     * @return list<array<string, mixed>>
     */
    public function rows(Organization $company, iterable $reviews, bool $results): array
    {
        $reviews = collect($reviews);
        $people = $this->directory->many($company, $reviews->flatMap(fn (Review $review) => array_filter([$review->employee_id, $review->manager_employee_id]))->unique()->values()->all());
        $cycles = $this->place->query(Cycle::class, $company)->whereKey($reviews->pluck('cycle_id')->unique()->all())->get()->keyBy(fn (Cycle $cycle) => $cycle->getKey());

        return $reviews->map(function (Review $review) use ($people, $cycles, $results) {
            $cycle = $cycles[$review->cycle_id] ?? null;
            $done = in_array($review->stage, [Review::ACKNOWLEDGE, Review::DONE], true);

            return [
                'id' => $review->getKey(), 'cycle_id' => $review->cycle_id, 'cycle_name' => (string) $cycle?->textIn('name'), 'cycle_status' => $cycle?->status,
                'employee' => ['id' => $review->employee_id, 'name' => $people[$review->employee_id]->name ?? '', 'code' => $people[$review->employee_id]->code ?? ''],
                'manager_name' => $review->manager_employee_id === null ? null : ($people[$review->manager_employee_id]->name ?? null),
                'stage' => $review->stage, 'goals_sent' => $review->goals_submitted_at !== null,
                'due' => $cycle === null ? null : $this->reviews->dueOf($cycle, $review)?->format('Y-m-d'),
                'score' => $results && $done ? $review->score : null, 'band' => $results && $done ? $review->band : null, 'version' => $review->version,
            ];
        })->values()->all();
    }

    /**
     * One review as this person may see it, with what they may do.
     *
     * @return array<string, mixed>
     */
    public function review(Organization $company, Review $review, User $viewer): array
    {
        $cycle = $this->reviews->cycleOf($company, $review);
        $unit = Organization::query()->find($review->unit_id);
        $isEmployee = $this->reviews->isEmployee($company, $review, $viewer);
        $isReviewer = $this->reviews->isReviewer($company, $review, $viewer);
        $oversees = ! $isEmployee && $unit !== null && Gate::any(['appraisals.view', 'appraisals.manage'], $unit);
        $stage = array_search($review->stage, Review::STAGES, true);
        $managerDone = $stage >= array_search(Review::ACKNOWLEDGE, Review::STAGES, true);
        $selfSent = $stage >= array_search(Review::MANAGER, Review::STAGES, true);
        $seeSelf = $isEmployee || $oversees || ($isReviewer && $selfSent);
        $seeManager = $isReviewer || $oversees || ($isEmployee && $managerDone);
        $open = $cycle->status === Cycle::OPEN;

        $goals = $this->place->query(Goal::class, $company)->where('review_id', $review->getKey())->orderBy('sort_order')->get();
        $ratings = $this->place->query(Rating::class, $company)->where('review_id', $review->getKey())->get();
        $byGoal = $ratings->keyBy('goal_id');
        $competencies = $this->place->query(Competency::class, $company)->whereKey($ratings->pluck('competency_id')->filter()->all())->get()->keyBy(fn (Competency $item) => $item->getKey());
        $side = fn (?Rating $rating) => [
            'rating_id' => $rating?->getKey(),
            'self_rating' => $seeSelf ? $rating?->self_rating : null, 'self_comment' => $seeSelf ? $rating?->self_comment : null,
            'manager_rating' => $seeManager ? $rating?->manager_rating : null, 'manager_comment' => $seeManager ? $rating?->manager_comment : null,
        ];
        $people = $this->directory->many($company, array_filter([$review->employee_id, $review->manager_employee_id]));
        $steps = $this->place->query(Step::class, $company)->where('review_id', $review->getKey())->orderBy('created_at')->get();
        $names = User::query()->whereKey($steps->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $bands = collect((array) $this->rules->get('appraisals.bands', $this->contexts->forOrganization($unit ?? $company)))
            ->map(fn ($band) => ['key' => $band['key'] ?? '', 'min' => (int) ($band['min'] ?? 0), 'name' => (array) ($band['name'] ?? [])])->values()->all();

        return [
            ...$this->rows($company, [$review], $seeManager && ($managerDone || ! $isEmployee))[0],
            'cycle' => $this->cycle($cycle), 'scale' => $cycle->scale, 'bands' => $bands,
            'employee' => ['id' => $review->employee_id, 'name' => $people[$review->employee_id]->name ?? '', 'code' => $people[$review->employee_id]->code ?? ''],
            'goals' => $goals->map(fn (Goal $goal) => [
                'id' => $goal->getKey(), 'title' => $goal->title, 'description' => $goal->description, 'weight' => $goal->weight, ...$side($byGoal[$goal->getKey()] ?? null),
            ])->values()->all(),
            'competencies' => $ratings->filter(fn (Rating $rating) => $rating->competency_id !== null)->map(fn (Rating $rating) => [
                'id' => $rating->competency_id, 'name' => isset($competencies[$rating->competency_id]) ? $competencies[$rating->competency_id]->textIn('name') : '', ...$side($rating),
            ])->sortBy('name')->values()->all(),
            'self_summary' => $seeSelf ? $review->self_summary : null,
            'manager_summary' => $seeManager ? $review->manager_summary : null,
            'goals_score' => $seeManager && ($managerDone || ! $isEmployee) ? $review->goals_score : null,
            'competencies_score' => $seeManager && ($managerDone || ! $isEmployee) ? $review->competencies_score : null,
            'employee_comment' => $review->employee_comment,
            'history' => $steps->map(fn (Step $step) => [
                'from' => $step->from_stage, 'to' => $step->to_stage, 'note' => $step->note, 'by' => $step->user_id === null ? null : ($names[$step->user_id] ?? ''),
                'at' => $step->created_at?->toIso8601String(),
            ])->values()->all(),
            'role' => $isEmployee ? 'employee' : ($isReviewer ? 'reviewer' : 'oversees'),
            'can' => [
                'edit_goals' => $open && $review->stage === Review::GOALS && ($isReviewer || ($isEmployee && $review->goals_submitted_at === null)),
                'submit_goals' => $open && $review->stage === Review::GOALS && $isEmployee && $review->goals_submitted_at === null,
                'approve_goals' => $open && $review->stage === Review::GOALS && $isReviewer,
                'rate' => $open && (($review->stage === Review::SELF && $isEmployee) || ($review->stage === Review::MANAGER && $isReviewer)),
                'submit_self' => $open && $review->stage === Review::SELF && $isEmployee,
                'submit_manager' => $open && $review->stage === Review::MANAGER && $isReviewer,
                'acknowledge' => $open && $review->stage === Review::ACKNOWLEDGE && $isEmployee,
                'send_back' => $open && $review->stage !== Review::GOALS && ! $isEmployee && $unit !== null && Gate::allows('appraisals.manage', $unit),
            ],
        ];
    }
}
