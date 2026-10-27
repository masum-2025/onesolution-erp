<?php

namespace Modules\Appraisals\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Appraisals\Exceptions\AppraisalsException;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Rating;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Models\Step;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Review cycles. Written as a draft (a name per language, the unit whose
 * people take part, the period, the last day of each step), then opened:
 * everyone employed there today gets a review, with their HRM manager as
 * it is now (someone managing appraisals reviews those without one), and
 * the rules that score it (goal share, self review, the scale) are kept as
 * they are now. The last days may still move while it is open. Closed,
 * nothing changes any more. Every step audited.
 */
class Cycles
{
    public function __construct(
        private Place $place,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * A new draft, or changes to a cycle with the version seen (an open one: only its last days).
     *
     * @param  array<string, mixed>  $data  Validated by CycleRequest.
     */
    public function save(Organization $company, ?Cycle $cycle, array $data, User $actor): Cycle
    {
        return $this->place->transaction($company, function () use ($company, $cycle, $data, $actor) {
            if ($cycle !== null) {
                $cycle = $this->fresh($company, $cycle, (int) $data['base_version']);
                if ($cycle->status === Cycle::CLOSED) {
                    throw AppraisalsException::notDraft();
                }
                if ($cycle->status === Cycle::OPEN) {
                    $data = array_intersect_key($data, array_flip(['goals_due', 'self_due', 'manager_due']));
                }
            }
            $cycle ??= new Cycle([
                'organization_id' => $company->getKey(), 'status' => Cycle::DRAFT, 'created_by' => $actor->getKey(), 'version' => 0,
                'goal_share_percent' => 0, 'self_review' => true, 'scale' => [],
            ]);
            $cycle->fill(array_intersect_key($data, array_flip(['unit_id', 'period_from', 'period_to', 'goals_due', 'self_due', 'manager_due'])));
            if (isset($data['name'])) {
                $cycle->putTexts('name', $data['name']);
            }
            $this->checkDays($cycle);
            $added = ! $cycle->exists;
            $cycle->forceFill(['version' => $cycle->version + 1])->save();
            $this->audit->record($added ? 'appraisals.cycle_added' : 'appraisals.cycle_changed', $cycle, new: $this->values($cycle), actor: $actor, organizationId: $company->getKey());

            return $cycle;
        });
    }

    /** A draft opened: a review for everyone employed at its unit (and below) today. */
    public function open(Organization $company, Cycle $cycle, int $baseVersion, User $actor): Cycle
    {
        [$cycle, $people] = $this->place->transaction($company, function () use ($company, $cycle, $baseVersion, $actor) {
            $cycle = $this->fresh($company, $cycle, $baseVersion);
            if ($cycle->status !== Cycle::DRAFT) {
                throw AppraisalsException::notDraft();
            }
            $unit = Organization::query()->findOrFail($cycle->unit_id);
            $people = $this->directory->inUnits($company, $this->place->subtreeIds($unit), $this->place->today($company));
            if ($people === []) {
                throw AppraisalsException::nobody();
            }
            $context = $this->contexts->forOrganization($unit);
            $scale = array_values(array_filter((array) $this->rules->get('appraisals.rating_scale', $context), fn ($step) => isset($step['value'])));
            usort($scale, fn (array $a, array $b) => $a['value'] <=> $b['value']);
            $selfReview = (bool) $this->rules->get('appraisals.self_review', $context);
            $cycle->forceFill([
                'status' => Cycle::OPEN, 'opened_at' => now(), 'scale' => $scale, 'self_review' => $selfReview,
                'goal_share_percent' => (int) $this->rules->get('appraisals.goal_share_percent', $context), 'version' => $cycle->version + 1,
            ])->save();

            $managers = $this->directory->many($company, array_values(array_filter(array_map(fn ($person) => $person->managerId, $people))));
            $competencies = app(Competencies::class)->of($company, activeOnly: true);
            foreach ($people as $person) {
                $manager = $person->managerId === null ? null : ($managers[$person->managerId] ?? null);
                $review = new Review;
                $review->fill([
                    'organization_id' => $company->getKey(), 'cycle_id' => $cycle->getKey(), 'unit_id' => $person->unitId, 'employee_id' => $person->id,
                    'manager_employee_id' => $manager?->id, 'manager_user_id' => $manager?->userId !== $person->userId ? $manager?->userId : null,
                    'stage' => Review::GOALS, 'version' => 1,
                ])->save();
                foreach ($competencies as $competency) {
                    (new Rating)->fill(['organization_id' => $company->getKey(), 'review_id' => $review->getKey(), 'competency_id' => $competency->getKey()])->save();
                }
                (new Step)->fill(['organization_id' => $company->getKey(), 'review_id' => $review->getKey(), 'to_stage' => Review::GOALS, 'user_id' => $actor->getKey()])->save();
            }
            $this->audit->record('appraisals.cycle_opened', $cycle, new: [...$this->values($cycle), 'reviews' => count($people)], actor: $actor, organizationId: $company->getKey());

            return [$cycle, $people];
        });

        $users = User::query()->whereKey(array_values(array_filter(array_map(fn ($person) => $person->userId, $people))))->get();
        if ($users->isNotEmpty()) {
            $this->notifier->notify('appraisals.opened', $users, fn (string $locale) => [
                'organization' => $company->displayName($locale), 'cycle' => $cycle->textIn('name', $locale),
                'due' => Reviews::date($cycle->goals_due, $locale),
            ], $company->partner, $company);
        }

        return $cycle;
    }

    /** An open cycle closed: nothing of it changes any more (reviews not done stay as they are). */
    public function close(Organization $company, Cycle $cycle, int $baseVersion, User $actor): Cycle
    {
        return $this->place->transaction($company, function () use ($company, $cycle, $baseVersion, $actor) {
            $cycle = $this->fresh($company, $cycle, $baseVersion);
            if ($cycle->status !== Cycle::OPEN) {
                throw AppraisalsException::notOpen();
            }
            $cycle->forceFill(['status' => Cycle::CLOSED, 'closed_at' => now(), 'version' => $cycle->version + 1])->save();
            $this->audit->record('appraisals.cycle_closed', $cycle, new: $this->values($cycle), actor: $actor, organizationId: $company->getKey());

            return $cycle;
        });
    }

    private function checkDays(Cycle $cycle): void
    {
        $day = fn ($value) => $value === null ? null : (is_string($value) ? $value : $value->toDateString());
        [$from, $to, $goals, $self, $manager] = [$day($cycle->period_from), $day($cycle->period_to), $day($cycle->goals_due), $day($cycle->self_due), $day($cycle->manager_due)];
        $errors = [];
        if ($from !== null && $to !== null && $to < $from) {
            $errors['period_to'] = __('appraisals::appraisals.validation.period');
        }
        if ($goals !== null && $self !== null && $self < $goals) {
            $errors['self_due'] = __('appraisals::appraisals.validation.after_goals');
        }
        if ($manager !== null && $manager < ($self ?? $goals ?? $manager)) {
            $errors['manager_due'] = __('appraisals::appraisals.validation.after_self');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function fresh(Organization $company, Cycle $cycle, int $baseVersion): Cycle
    {
        /** @var Cycle $fresh */
        $fresh = $this->place->query(Cycle::class, $company)->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw AppraisalsException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Cycle $cycle): array
    {
        return [
            'name' => $cycle->texts('name'), 'unit_id' => $cycle->unit_id, 'status' => $cycle->status,
            'period_from' => $cycle->period_from?->toDateString(), 'period_to' => $cycle->period_to?->toDateString(),
            'goals_due' => $cycle->goals_due?->toDateString(), 'self_due' => $cycle->self_due?->toDateString(), 'manager_due' => $cycle->manager_due?->toDateString(),
        ];
    }
}
