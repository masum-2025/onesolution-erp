<?php

namespace Modules\Payroll\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Payroll\Contracts\PerformanceSource;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\IncrementApproval;
use Modules\Payroll\Models\IncrementLine;
use Modules\Payroll\Models\IncrementRound;
use Modules\Payroll\Models\Run;

/**
 * Salary increments, usually after an appraisal cycle.
 *
 * A round is for a day (effective_from) and may follow a finished cycle of
 * a PerformanceSource (Appraisals): each employee employed that day with a
 * salary gets a line; the rate comes from the band (rule
 * payroll.increment_by_band, capped by payroll.increment_max_percent) or is
 * set by hand with a reason; the new basic is rounded (rule
 * payroll.increment_round_to, never below the basic now). Sent by payroll
 * staff, approved by payroll.salary_approval_levels other people, then
 * applied: each new basic becomes a salary from that day (Salaries::assign,
 * audited one by one), and the people are told without any amount. A month
 * whose payroll (or a later one) is sent or approved cannot be a round's.
 */
class Increments
{
    public function __construct(
        private Payrolls $payrolls,
        private Salaries $salaries,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * What a round can follow: finished cycles and the bands, from every
     * performance source (none: rates are set by hand).
     *
     * @return array{cycles: list<array<string, mixed>>, bands: list<array{key: string, name: array<string, string>}>}
     */
    public function sources(Organization $company): array
    {
        $cycles = [];
        $bands = [];
        foreach ($this->performanceSources() as $source) {
            $cycles = [...$cycles, ...$source->cycles($company)];
            $bands = $bands ?: $source->bands($company);
        }

        return ['cycles' => $cycles, 'bands' => $bands];
    }

    /**
     * @param  array{title: array<string, string>, effective_from: string, cycle_id?: string|null}  $data
     */
    public function open(Organization $company, array $data, User $actor): IncrementRound
    {
        $from = CarbonImmutable::parse($data['effective_from'], 'UTC');
        $this->openMonth($company, $from);
        $cycle = $data['cycle_id'] ?? null;
        if ($cycle !== null && ! collect($this->sources($company)['cycles'])->contains('id', $cycle)) {
            throw ValidationException::withMessages(['cycle_id' => __('payroll::payroll.validation.increment_cycle')]);
        }

        return $this->payrolls->transaction($company, function () use ($company, $data, $from, $cycle, $actor) {
            $round = new IncrementRound;
            $round->fill([
                'organization_id' => $company->getKey(), 'cycle_id' => $cycle, 'effective_from' => $from->toDateString(), 'status' => IncrementRound::DRAFT,
                'currency_code' => $this->payrolls->currency($company), 'created_by' => $actor->getKey(), 'version' => 1,
            ]);
            $round->putTexts('title', $data['title']);
            $round->save();
            $this->fillLines($company, $round);
            $this->audit->record('payroll.increment_opened', $round, new: $this->values($round), actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /** Fill every line of a draft again (new people, salaries, results); rates set by hand and people left out stay so. */
    public function refill(Organization $company, IncrementRound $round, int $baseVersion, User $actor): IncrementRound
    {
        return $this->payrolls->transaction($company, function () use ($company, $round, $baseVersion, $actor) {
            $round = $this->draft($company, $round, $baseVersion);
            $this->fillLines($company, $round);
            $round->forceFill(['reject_reason' => null, 'version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_filled', $round, new: $this->values($round), actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /**
     * Leave someone out, or set the rate (basis points) or the new basic by hand with a
     * reason; both null puts the line back on the rule.
     *
     * @param  array{excluded?: bool, rate_bp?: int|null, new_minor?: int|null, reason?: string|null}  $data
     */
    public function changeLine(Organization $company, IncrementRound $round, IncrementLine $line, array $data, User $actor): IncrementLine
    {
        return $this->payrolls->transaction($company, function () use ($company, $round, $line, $data, $actor) {
            $round = $this->draft($company, $round, null);
            $old = $line->only(['excluded', 'rate_bp', 'rate_source', 'new_minor']);
            $max = $this->maxRate($company);
            if (array_key_exists('excluded', $data)) {
                $line->excluded = (bool) $data['excluded'];
            }
            $byRate = array_key_exists('rate_bp', $data) && $data['rate_bp'] !== null;
            $byAmount = array_key_exists('new_minor', $data) && $data['new_minor'] !== null;
            if ($byRate || $byAmount) {
                if (blank($data['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => __('payroll::payroll.validation.increment_reason')]);
                }
                $new = $byAmount ? (int) $data['new_minor'] : $line->current_minor + PayCalculator::share($line->current_minor, (int) $data['rate_bp']);
                if ($new < $line->current_minor) {
                    throw ValidationException::withMessages(['new_minor' => __('payroll::payroll.validation.increment_below')]);
                }
                $rate = $byAmount ? $this->rateOf($line->current_minor, $new) : (int) $data['rate_bp'];
                if ($rate > $max) {
                    throw ValidationException::withMessages([$byAmount ? 'new_minor' : 'rate_bp' => __('payroll::payroll.validation.increment_max', ['max' => $max / 100])]);
                }
                $line->fill(['rate_bp' => $rate, 'rate_source' => IncrementLine::BY_HAND, 'new_minor' => $new, 'reason' => trim((string) $data['reason'])]);
            } elseif (array_key_exists('rate_bp', $data) || array_key_exists('new_minor', $data)) {
                // Back on the rule.
                $line->fill(['rate_source' => IncrementLine::FROM_RULE, 'reason' => null]);
                $this->work($company, $line);
            }
            $line->save();
            $this->totals($company, $round);
            $round->forceFill(['version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_line_changed', $round, old: $old,
                new: $line->only(['employee_id', 'excluded', 'rate_bp', 'rate_source', 'new_minor']), reason: $line->reason, actor: $actor, organizationId: $company->getKey());

            return $line;
        });
    }

    public function submit(Organization $company, IncrementRound $round, int $baseVersion, User $actor): IncrementRound
    {
        return $this->payrolls->transaction($company, function () use ($company, $round, $baseVersion, $actor) {
            $round = $this->draft($company, $round, $baseVersion);
            $this->openMonth($company, $round->effective_from);
            if ($round->employees === 0) {
                throw PayrollException::incrementEmpty();
            }
            $round->forceFill(['status' => IncrementRound::PENDING, 'submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_submitted', $round, new: $this->values($round), actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /** One level of approval (each a different person, never the one who made or sent it); the last approves the round. */
    public function approve(Organization $company, IncrementRound $round, int $baseVersion, User $actor): IncrementRound
    {
        return $this->payrolls->transaction($company, function () use ($company, $round, $baseVersion, $actor) {
            $round = $this->pending($company, $round, $baseVersion, $actor);
            $approvals = $this->payrolls->query(IncrementApproval::class, $company)->where('round_id', $round->getKey());
            if ((clone $approvals)->where('user_id', $actor->getKey())->exists()) {
                throw PayrollException::alreadyApproved();
            }
            $level = (clone $approvals)->count() + 1;
            (new IncrementApproval)->fill(['organization_id' => $company->getKey(), 'round_id' => $round->getKey(), 'level' => $level, 'user_id' => $actor->getKey()])->save();
            $needed = $this->levelsNeeded($company);
            $this->audit->record('payroll.increment_approved_level', $round, new: ['level' => $level, 'of' => $needed], actor: $actor, organizationId: $company->getKey());
            if ($level < $needed) {
                $round->forceFill(['version' => $round->version + 1])->save();

                return $round;
            }
            $round->forceFill(['status' => IncrementRound::APPROVED, 'approved_at' => now(), 'version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_approved', $round, new: $this->values($round), actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    public function reject(Organization $company, IncrementRound $round, int $baseVersion, string $reason, User $actor): IncrementRound
    {
        return $this->payrolls->transaction($company, function () use ($company, $round, $baseVersion, $reason, $actor) {
            $round = $this->pending($company, $round, $baseVersion, $actor);
            $this->payrolls->query(IncrementApproval::class, $company)->where('round_id', $round->getKey())->delete();
            $round->forceFill(['status' => IncrementRound::DRAFT, 'reject_reason' => $reason, 'submitted_by' => null, 'submitted_at' => null, 'version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_rejected', $round, new: $this->values($round), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /**
     * Set the new basics as salaries from the day. A line whose salary
     * changed meanwhile is held (problem salary_changed) and nothing is set
     * for it. Each salary is audited (payroll.salary_set); the people with a
     * login are told their pay was revised, without any amount.
     */
    public function apply(Organization $company, IncrementRound $round, int $baseVersion, User $actor): IncrementRound
    {
        $round = $this->payrolls->transaction($company, function () use ($company, $round, $baseVersion, $actor) {
            $round = $this->lock($company, $round, $baseVersion);
            if ($round->status !== IncrementRound::APPROVED) {
                throw PayrollException::notApproved();
            }
            $this->openMonth($company, $round->effective_from);
            $title = $round->textIn('title');
            $applied = 0;
            foreach ($this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())->where('excluded', false)->orderBy('employee_name')->get() as $line) {
                if ($line->new_minor <= $line->current_minor) {
                    continue;
                }
                $employee = $this->directory->find($company, $line->employee_id);
                $now = $this->salaries->on($company, $line->employee_id, $round->effective_from);
                if ($employee === null || $now === null || $now->getKey() !== $line->salary_id || $now->basic_minor !== $line->current_minor) {
                    $line->forceFill(['problem' => 'salary_changed'])->save();

                    continue;
                }
                try {
                    $salary = $this->salaries->assign($company, $employee, [
                        'structure_id' => (string) $line->structure_id, 'basic_minor' => $line->new_minor, 'effective_from' => $round->effective_from->toDateString(),
                        'reason' => __('payroll::payroll.increments.salary_reason', ['title' => $title]),
                    ], $actor);
                } catch (ValidationException) {
                    // The structure was switched off meanwhile: held, the others go ahead.
                    $line->forceFill(['problem' => 'structure_off'])->save();

                    continue;
                }
                $line->forceFill(['applied_salary_id' => $salary->getKey(), 'problem' => null])->save();
                $applied++;
            }
            $round->forceFill(['status' => IncrementRound::APPLIED, 'applied_by' => $actor->getKey(), 'applied_at' => now(), 'version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_applied', $round, new: [...$this->values($round), 'applied' => $applied], actor: $actor, organizationId: $company->getKey());

            return $round;
        });
        $this->tell($company, $round);

        return $round;
    }

    /** A draft or an approved round not applied yet is called off (kept, with its lines). */
    public function cancel(Organization $company, IncrementRound $round, int $baseVersion, User $actor): IncrementRound
    {
        return $this->payrolls->transaction($company, function () use ($company, $round, $baseVersion, $actor) {
            $round = $this->lock($company, $round, $baseVersion);
            if (! in_array($round->status, [IncrementRound::DRAFT, IncrementRound::APPROVED], true)) {
                throw PayrollException::notDraft();
            }
            $round->forceFill(['status' => IncrementRound::CANCELLED, 'cancelled_at' => now(), 'version' => $round->version + 1])->save();
            $this->audit->record('payroll.increment_cancelled', $round, new: $this->values($round), actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /** How many approvals a round needs here. */
    public function levelsNeeded(Organization $company): int
    {
        return max(1, (int) $this->rules->get('payroll.salary_approval_levels', $this->contexts->forOrganization($company)));
    }

    /** Every employee employed on the day with a salary then: a line, worked out (or kept as set by hand). */
    private function fillLines(Organization $company, IncrementRound $round): void
    {
        $on = $round->effective_from;
        $kept = $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())->get()->keyBy('employee_id');
        $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())->delete();
        $results = $this->results($company, $round->cycle_id);

        foreach ($this->directory->inUnits($company, $this->payrolls->subtreeIds($company)) as $employee) {
            if ($employee->joinedOn->greaterThan($on) || ($employee->exitsOn !== null && $employee->exitsOn->lessThan($on))) {
                continue;
            }
            $salary = $this->salaries->on($company, $employee->id, $on);
            if ($salary === null) {
                continue;
            }
            $before = $kept[$employee->id] ?? null;
            $line = new IncrementLine;
            $line->fill([
                'organization_id' => $company->getKey(), 'round_id' => $round->getKey(), 'employee_id' => $employee->id, 'unit_id' => $employee->unitId,
                'employee_code' => $employee->code, 'employee_name' => $employee->name,
                'salary_id' => $salary->getKey(), 'structure_id' => $salary->structure_id, 'current_minor' => $salary->basic_minor,
                'score' => $results[$employee->id]['score'] ?? null, 'band' => $results[$employee->id]['band'] ?? null,
                'excluded' => (bool) ($before->excluded ?? false),
                'rate_source' => $before?->rate_source === IncrementLine::BY_HAND ? IncrementLine::BY_HAND : IncrementLine::FROM_RULE,
                'reason' => $before?->rate_source === IncrementLine::BY_HAND ? $before->reason : null,
            ]);
            if ($line->rate_source === IncrementLine::BY_HAND) {
                // Set by hand: the same rate on today's basic (the same amount when the basic is unchanged).
                $line->rate_bp = $before->rate_bp;
                $line->new_minor = $before->current_minor === $line->current_minor ? $before->new_minor
                    : $line->current_minor + PayCalculator::share($line->current_minor, $before->rate_bp);
            } else {
                $this->work($company, $line);
            }
            $line->save();
        }
        $this->totals($company, $round);
    }

    /** A line on the rule: the band's rate (capped), the new basic rounded, never below the basic now. */
    private function work(Organization $company, IncrementLine $line): void
    {
        $context = $this->contexts->forOrganization($company);
        $byBand = (array) $this->rules->get('payroll.increment_by_band', $context);
        if ($line->band === null || ! array_key_exists($line->band, $byBand)) {
            $line->fill(['rate_bp' => 0, 'rate_source' => IncrementLine::NONE, 'new_minor' => $line->current_minor]);

            return;
        }
        $rate = min(PayCalculator::toBasisPoints((string) $byBand[$line->band], 100) ?? 0, $this->maxRate($company));
        $line->fill(['rate_bp' => $rate, 'rate_source' => IncrementLine::FROM_RULE, 'new_minor' => $this->rounded($company, $line->current_minor, $rate)]);
    }

    /** The basic raised by the rate, to the nearest step of payroll.increment_round_to (same currency only), never below it now. */
    private function rounded(Organization $company, int $basic, int $rate): int
    {
        if ($rate <= 0) {
            return $basic;
        }
        $raised = $basic + PayCalculator::share($basic, $rate);
        $step = $this->rules->get('payroll.increment_round_to', $this->contexts->forOrganization($company));
        if (! is_array($step) || ($step['currency'] ?? null) !== $this->payrolls->currency($company) || (int) $step['amount'] <= 1) {
            return $raised;
        }
        $size = (int) $step['amount'];
        $nearest = intdiv($raised + intdiv($size, 2), $size) * $size;

        return $nearest > $basic ? $nearest : intdiv($raised + $size - 1, $size) * $size;
    }

    /** The highest rate allowed here, in basis points (payroll.increment_max_percent). */
    private function maxRate(Organization $company): int
    {
        return PayCalculator::toBasisPoints((string) $this->rules->get('payroll.increment_max_percent', $this->contexts->forOrganization($company)), 100) ?? 0;
    }

    /** The rate that takes the basic to the new one, in basis points, rounded half up. */
    private function rateOf(int $current, int $new): int
    {
        if ($current <= 0) {
            return 0;
        }

        return intdiv(($new - $current) * 10000 * 2 + $current, $current * 2);
    }

    /**
     * @return array<string, array{score: int|null, band: string|null}>
     */
    private function results(Organization $company, ?string $cycleId): array
    {
        if ($cycleId === null) {
            return [];
        }
        foreach ($this->performanceSources() as $source) {
            $results = $source->results($company, $cycleId);
            if ($results !== []) {
                return $results;
            }
        }

        return [];
    }

    /**
     * @return iterable<PerformanceSource>
     */
    private function performanceSources(): iterable
    {
        return app()->tagged('payroll.performance_sources');
    }

    private function totals(Organization $company, IncrementRound $round): void
    {
        $raised = $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())->where('excluded', false)
            ->whereColumn('new_minor', '>', 'current_minor')->get(['current_minor', 'new_minor']);
        $round->forceFill([
            'employees' => $raised->count(),
            'current_minor' => (int) $raised->sum('current_minor'),
            'new_minor' => (int) $raised->sum('new_minor'),
        ])->save();
    }

    /** Applying would change a month whose payroll (or a later one) is sent or approved: refused. */
    private function openMonth(Organization $company, CarbonImmutable $from): void
    {
        $period = $from->format('Y-m');
        $closed = $this->payrolls->query(Run::class, $company)->where('period', '>=', $period)->where('status', '!=', Run::DRAFT)->exists();
        if ($closed) {
            throw PayrollException::incrementMonthLocked($period);
        }
    }

    /** The people raised, told by mail that their pay changed from the day (no amount: they see it on their payslips). */
    private function tell(Organization $company, IncrementRound $round): void
    {
        $employees = $this->directory->many($company, $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())
            ->whereNotNull('applied_salary_id')->pluck('employee_id')->map(fn ($id) => (string) $id)->all());
        $users = User::query()->whereKey(collect($employees)->pluck('userId')->filter()->values()->all())->get();
        if ($users->isEmpty()) {
            return;
        }
        $this->notifier->notify(
            'payroll.salary_revised',
            $users,
            fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'date' => $round->effective_from->locale($locale)->isoFormat('LL'),
            ],
            $company->partner,
            $company,
        );
    }

    private function draft(Organization $company, IncrementRound $round, ?int $baseVersion): IncrementRound
    {
        $round = $this->lock($company, $round, $baseVersion);
        if ($round->status !== IncrementRound::DRAFT) {
            throw PayrollException::notDraft();
        }

        return $round;
    }

    private function pending(Organization $company, IncrementRound $round, int $baseVersion, User $actor): IncrementRound
    {
        $round = $this->lock($company, $round, $baseVersion);
        if ($round->status !== IncrementRound::PENDING) {
            throw PayrollException::notPending();
        }
        if (in_array($actor->getKey(), [$round->created_by, $round->submitted_by], true)) {
            throw PayrollException::ownRun();
        }

        return $round;
    }

    private function lock(Organization $company, IncrementRound $round, ?int $baseVersion): IncrementRound
    {
        /** @var IncrementRound $fresh */
        $fresh = $this->payrolls->query(IncrementRound::class, $company)->whereKey($round->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw PayrollException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(IncrementRound $round): array
    {
        return [...$round->only(['status', 'cycle_id', 'employees', 'current_minor', 'new_minor', 'currency_code']), 'effective_from' => $round->effective_from->toDateString()];
    }
}
