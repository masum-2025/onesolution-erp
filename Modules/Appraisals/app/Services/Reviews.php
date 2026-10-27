<?php

namespace Modules\Appraisals\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Appraisals\Exceptions\AppraisalsException;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Goal;
use Modules\Appraisals\Models\Rating;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Models\Step;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * One employee's review, step by step. Goals: the employee (or their
 * reviewer) writes goals with weights (adding up to 100 when the rule
 * asks); the employee sends them and the reviewer approves them. Self: the
 * employee rates each goal and competency (skipped when the cycle has no
 * self review). Manager: the reviewer rates them; the scores and the band
 * are worked out (Scoring). Acknowledge: the employee reads the result and
 * says so, with a comment if they wish. The reviewer is the employee's
 * manager as at the cycle's opening, else someone managing appraisals
 * there; nobody reviews themselves. Someone managing appraisals may send a
 * review back a step, with a reason. Every step kept and audited; whoever
 * acts next is told (no ratings or comments in the mail).
 */
class Reviews
{
    public function __construct(
        private Place $place,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /** Whether this person is the employee of the review (their login linked in HRM). */
    public function isEmployee(Organization $company, Review $review, User $user): bool
    {
        return $this->directory->find($company, $review->employee_id)?->userId === $user->getKey();
    }

    /** Whether this person reviews the employee: their manager, else someone managing appraisals there; never themselves. */
    public function isReviewer(Organization $company, Review $review, User $user): bool
    {
        if ($this->isEmployee($company, $review, $user)) {
            return false;
        }

        return $this->reviewerUsers($company, $review)->contains(fn (User $person) => $person->getKey() === $user->getKey());
    }

    /**
     * The goals of a review in the goals step, sent whole (goals left out go, with their ratings).
     *
     * @param  list<array{id?: string|null, title: string, description?: string|null, weight: int}>  $goals
     */
    public function saveGoals(Organization $company, Review $review, array $goals, int $baseVersion, User $actor): Review
    {
        return $this->place->transaction($company, function () use ($company, $review, $goals, $baseVersion, $actor) {
            $review = $this->working($company, $review, $baseVersion, [Review::GOALS]);
            // The employee until they send them; the reviewer any time in this step.
            $this->must(($this->isEmployee($company, $review, $actor) && $review->goals_submitted_at === null) || $this->isReviewer($company, $review, $actor));
            $existing = $this->place->query(Goal::class, $company)->where('review_id', $review->getKey())->get()->keyBy(fn (Goal $goal) => $goal->getKey());
            $kept = [];
            foreach (array_values($goals) as $index => $data) {
                $goal = isset($data['id']) && isset($existing[$data['id']]) ? $existing[$data['id']] : new Goal(['organization_id' => $company->getKey(), 'review_id' => $review->getKey()]);
                $goal->fill(['title' => trim($data['title']), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'weight' => $data['weight'], 'sort_order' => $index])->save();
                if (! isset($existing[$goal->getKey()])) {
                    (new Rating)->fill(['organization_id' => $company->getKey(), 'review_id' => $review->getKey(), 'goal_id' => $goal->getKey()])->save();
                }
                $kept[] = $goal->getKey();
            }
            $gone = $existing->keys()->diff($kept)->all();
            if ($gone !== []) {
                $this->place->query(Rating::class, $company)->whereIn('goal_id', $gone)->delete();
                $this->place->query(Goal::class, $company)->whereKey($gone)->delete();
            }
            $review->forceFill(['version' => $review->version + 1])->save();

            return $review;
        });
    }

    /**
     * One side's ratings and comments, with the version seen: the employee's in the self step, the reviewer's in
     * the manager step. Ratings are values of the cycle's scale.
     *
     * @param  array{ratings: list<array{id: string, rating?: int|null, comment?: string|null}>, summary?: string|null}  $data
     */
    public function rate(Organization $company, Review $review, array $data, int $baseVersion, User $actor): Review
    {
        return $this->place->transaction($company, function () use ($company, $review, $data, $baseVersion, $actor) {
            $review = $this->working($company, $review, $baseVersion, [Review::SELF, Review::MANAGER]);
            $side = $review->stage === Review::SELF ? 'self' : 'manager';
            $this->must($side === 'self' ? $this->isEmployee($company, $review, $actor) : $this->isReviewer($company, $review, $actor));
            $values = array_column($this->cycleOf($company, $review)->scale, 'value');
            $ratings = $this->place->query(Rating::class, $company)->where('review_id', $review->getKey())->get()->keyBy(fn (Rating $rating) => $rating->getKey());
            $errors = [];
            foreach (array_values($data['ratings']) as $index => $entry) {
                $rating = $ratings[$entry['id']] ?? null;
                if ($rating === null) {
                    $errors["ratings.{$index}.id"] = __('appraisals::appraisals.validation.rating_unknown');

                    continue;
                }
                if (isset($entry['rating']) && ! in_array((int) $entry['rating'], $values, true)) {
                    $errors["ratings.{$index}.rating"] = __('appraisals::appraisals.validation.rating_scale');

                    continue;
                }
                $rating->fill([
                    "{$side}_rating" => isset($entry['rating']) ? (int) $entry['rating'] : null,
                    "{$side}_comment" => trim((string) ($entry['comment'] ?? '')) ?: null,
                ])->save();
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            if (array_key_exists('summary', $data)) {
                $review->forceFill(["{$side}_summary" => trim((string) $data['summary']) ?: null]);
            }
            $review->forceFill(['version' => $review->version + 1])->save();

            return $review;
        });
    }

    /**
     * submit_goals | approve_goals | submit_self | submit_manager | acknowledge (the employee or the reviewer, as
     * the step says) | send_back (someone managing appraisals: one step back, with a reason).
     */
    public function step(Organization $company, Review $review, string $step, int $baseVersion, ?string $note, User $actor): Review
    {
        [$review, $tell] = $this->place->transaction($company, function () use ($company, $review, $step, $baseVersion, $note, $actor) {
            $cycle = null;
            $from = null;
            $tell = null;
            switch ($step) {
                case 'submit_goals':
                    $review = $this->working($company, $review, $baseVersion, [Review::GOALS]);
                    $this->must($this->isEmployee($company, $review, $actor));
                    $this->checkGoals($company, $review);
                    $review->goals_submitted_at = now();
                    $tell = 'reviewer';
                    break;
                case 'approve_goals':
                    $review = $this->working($company, $review, $baseVersion, [Review::GOALS]);
                    $this->must($this->isReviewer($company, $review, $actor));
                    $this->checkGoals($company, $review);
                    $cycle = $this->cycleOf($company, $review);
                    $review->stage = $cycle->self_review ? Review::SELF : Review::MANAGER;
                    $tell = $cycle->self_review ? 'employee' : null;
                    break;
                case 'submit_self':
                    $review = $this->working($company, $review, $baseVersion, [Review::SELF]);
                    $this->must($this->isEmployee($company, $review, $actor));
                    $this->checkRated($company, $review, 'self');
                    $review->stage = Review::MANAGER;
                    $tell = 'reviewer';
                    break;
                case 'submit_manager':
                    $review = $this->working($company, $review, $baseVersion, [Review::MANAGER]);
                    $this->must($this->isReviewer($company, $review, $actor));
                    $this->checkRated($company, $review, 'manager');
                    $this->score($company, $review);
                    $review->stage = Review::ACKNOWLEDGE;
                    $tell = 'employee';
                    break;
                case 'acknowledge':
                    $review = $this->working($company, $review, $baseVersion, [Review::ACKNOWLEDGE]);
                    $this->must($this->isEmployee($company, $review, $actor));
                    $review->forceFill(['stage' => Review::DONE, 'employee_comment' => trim((string) $note) ?: null, 'completed_at' => now()]);
                    break;
                default: // send_back
                    $review = $this->working($company, $review, $baseVersion, [Review::SELF, Review::MANAGER, Review::ACKNOWLEDGE, Review::DONE]);
                    $unit = Organization::query()->findOrFail($review->unit_id);
                    $this->must(Gate::forUser($actor)->allows('appraisals.manage', $unit) && ! $this->isEmployee($company, $review, $actor));
                    $cycle = $this->cycleOf($company, $review);
                    $back = match ($review->stage) {
                        Review::SELF => Review::GOALS,
                        Review::MANAGER => $cycle->self_review ? Review::SELF : Review::GOALS,
                        default => Review::MANAGER,
                    };
                    $review->forceFill([
                        'stage' => $back, 'completed_at' => null, 'goals_score' => null, 'competencies_score' => null, 'score' => null, 'band' => null,
                        ...($back === Review::GOALS ? ['goals_submitted_at' => null] : []),
                    ]);
                    $tell = $back === Review::MANAGER ? 'reviewer' : 'employee';
            }
            $from = $review->getOriginal('stage');
            $review->forceFill(['version' => $review->version + 1])->save();
            (new Step)->fill([
                'organization_id' => $company->getKey(), 'review_id' => $review->getKey(), 'from_stage' => $from,
                'to_stage' => $step === 'submit_goals' ? 'goals_sent' : $review->stage, 'note' => $note === null ? null : Str::limit($note, 490, ''),
                'user_id' => $actor->getKey(),
            ])->save();
            $this->audit->record("appraisals.{$step}", $review, old: ['stage' => $from], new: ['stage' => $review->stage, 'score' => $review->score, 'band' => $review->band],
                reason: $step === 'send_back' ? $note : null, actor: $actor, organizationId: $company->getKey());

            return [$review, $tell];
        });

        if ($tell !== null) {
            $this->tell('appraisals.your_turn', $company, $review, $tell === 'employee' ? $this->employeeUsers($company, $review) : $this->reviewerUsers($company, $review));
        }

        return $review;
    }

    /**
     * Reviews whose step ends in appraisals.reminder_days days, or tomorrow: whoever must act is reminded. How many.
     */
    public function remind(Organization $company): int
    {
        $today = $this->place->today($company);
        $count = 0;
        $cycles = $this->place->query(Cycle::class, $company)->where('status', Cycle::OPEN)->get();
        foreach ($cycles as $cycle) {
            $days = (int) $this->rules->get('appraisals.reminder_days', $this->contexts->forOrganization(Organization::query()->find($cycle->unit_id) ?? $company));
            $reviews = $this->place->query(Review::class, $company)->where('cycle_id', $cycle->getKey())->whereIn('stage', [Review::GOALS, Review::SELF, Review::MANAGER])->get();
            foreach ($reviews as $review) {
                $due = $this->dueOf($cycle, $review);
                if ($due === null) {
                    continue;
                }
                $left = (int) $today->diffInDays(CarbonImmutable::parse($due->toDateString(), 'UTC'), false);
                if ($left !== $days && $left !== 1) {
                    continue;
                }
                $employeeActs = $review->stage === Review::SELF || ($review->stage === Review::GOALS && $review->goals_submitted_at === null);
                $this->tell('appraisals.reminder', $company, $review, $employeeActs ? $this->employeeUsers($company, $review) : $this->reviewerUsers($company, $review));
                $count++;
            }
        }

        return $count;
    }

    /** The last day of the review's current step. */
    public function dueOf(Cycle $cycle, Review $review): ?\DateTimeInterface
    {
        return match ($review->stage) {
            Review::GOALS => $cycle->goals_due,
            Review::SELF => $cycle->self_due ?? $cycle->manager_due,
            Review::MANAGER => $cycle->manager_due,
            default => null,
        };
    }

    public function cycleOf(Organization $company, Review $review): Cycle
    {
        return $this->place->query(Cycle::class, $company)->whereKey($review->cycle_id)->firstOrFail();
    }

    /** "28 October 2026" in the language. */
    public static function date(?\DateTimeInterface $date, string $locale): string
    {
        return $date === null ? '' : CarbonImmutable::parse($date->format('Y-m-d'), 'UTC')->locale($locale)->isoFormat('LL');
    }

    /** The scores and the band from the reviewer's ratings. */
    private function score(Organization $company, Review $review): void
    {
        $cycle = $this->cycleOf($company, $review);
        $values = array_column($cycle->scale, 'value');
        [$low, $high] = [(int) min($values), (int) max($values)];
        $ratings = $this->place->query(Rating::class, $company)->where('review_id', $review->getKey())->get();
        $weights = $this->place->query(Goal::class, $company)->where('review_id', $review->getKey())->pluck('weight', 'id');
        $goals = $ratings->filter(fn (Rating $rating) => $rating->goal_id !== null && $rating->manager_rating !== null)
            ->map(fn (Rating $rating) => ['weight' => (int) ($weights[$rating->goal_id] ?? 0), 'rating' => $rating->manager_rating])->values()->all();
        $competencies = $ratings->filter(fn (Rating $rating) => $rating->competency_id !== null && $rating->manager_rating !== null)->pluck('manager_rating')->map(fn ($value) => (int) $value)->values()->all();
        $goalScore = Scoring::goals($goals, $low, $high);
        $competencyScore = Scoring::competencies($competencies, $low, $high);
        $score = Scoring::overall($goalScore, $competencyScore, $cycle->goal_share_percent) ?? 0;
        $bands = (array) $this->rules->get('appraisals.bands', $this->contexts->forOrganization(Organization::query()->find($review->unit_id) ?? $company));
        $review->forceFill(['goals_score' => $goalScore, 'competencies_score' => $competencyScore, 'score' => $score, 'band' => Scoring::band($score, $bands)]);
    }

    private function checkGoals(Organization $company, Review $review): void
    {
        $weights = $this->place->query(Goal::class, $company)->where('review_id', $review->getKey())->pluck('weight')->all();
        if ($weights === []) {
            throw ValidationException::withMessages(['goals' => __('appraisals::appraisals.validation.no_goals')]);
        }
        $unit = Organization::query()->find($review->unit_id) ?? $company;
        if ((bool) $this->rules->get('appraisals.goals_total_100', $this->contexts->forOrganization($unit)) && array_sum($weights) !== 100) {
            throw ValidationException::withMessages(['goals' => __('appraisals::appraisals.validation.total_100', ['total' => array_sum($weights)])]);
        }
    }

    private function checkRated(Organization $company, Review $review, string $side): void
    {
        $missing = $this->place->query(Rating::class, $company)->where('review_id', $review->getKey())->whereNull("{$side}_rating")->count();
        if ($missing > 0) {
            throw ValidationException::withMessages(['ratings' => trans_choice('appraisals::appraisals.validation.unrated', $missing, ['count' => $missing])]);
        }
    }

    /**
     * The review, fresh, with the version seen, at one of the stages, in an open cycle.
     *
     * @param  list<string>  $stages
     */
    private function working(Organization $company, Review $review, int $baseVersion, array $stages): Review
    {
        /** @var Review $fresh */
        $fresh = $this->place->query(Review::class, $company)->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw AppraisalsException::versionConflict(['version' => $fresh->version]);
        }
        if ($this->cycleOf($company, $fresh)->status !== Cycle::OPEN) {
            throw AppraisalsException::notOpen();
        }
        if (! in_array($fresh->stage, $stages, true)) {
            throw AppraisalsException::wrongStage();
        }

        return $fresh;
    }

    private function must(bool $allowed): void
    {
        if (! $allowed) {
            throw AppraisalsException::notYours();
        }
    }

    /** @return Collection<int, User> */
    private function employeeUsers(Organization $company, Review $review): Collection
    {
        $userId = $this->directory->find($company, $review->employee_id)?->userId;

        return $userId === null ? collect() : User::query()->whereKey($userId)->get();
    }

    /**
     * Who reviews the employee: their manager (as at the cycle's opening) when they may review there; else the
     * people managing appraisals there. Never the employee.
     *
     * @return Collection<int, User>
     */
    public function reviewerUsers(Organization $company, Review $review): Collection
    {
        $unit = Organization::query()->find($review->unit_id);
        if ($unit === null) {
            return collect();
        }
        $employee = $this->directory->find($company, $review->employee_id)?->userId;
        // Gates answer for the signed-in person's context; for someone else ask who holds the permission there.
        if ($review->manager_user_id !== null && $this->recipients->holding('appraisals.review', $unit)->contains(fn (User $user) => $user->getKey() === $review->manager_user_id)) {
            return User::query()->whereKey($review->manager_user_id)->get();
        }

        return $this->recipients->holding('appraisals.manage', $unit)->reject(fn (User $user) => $user->getKey() === $employee)->values();
    }

    private function tell(string $key, Organization $company, Review $review, Collection $to): void
    {
        if ($to->isEmpty()) {
            return;
        }
        $cycle = $this->cycleOf($company, $review);
        $employee = $this->directory->find($company, $review->employee_id);
        $this->notifier->notify($key, $to, fn (string $locale) => [
            'organization' => $company->displayName($locale), 'cycle' => $cycle->textIn('name', $locale), 'employee' => (string) $employee?->name,
            'step' => __("appraisals::appraisals.stages.{$review->stage}", [], $locale), 'due' => self::date($this->dueOf($cycle, $review), $locale),
        ], $company->partner, $company, "/appraisals/reviews/{$review->getKey()}");
    }
}
