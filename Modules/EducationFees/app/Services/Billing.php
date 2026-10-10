<?php

namespace Modules\EducationFees\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\TaxCodes;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Events\BillCancelled;
use Modules\EducationFees\Events\BillIssued;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\BillLine;
use Modules\EducationFees\Models\Concession;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\FeeRun;

/**
 * Billing students.
 *
 * - A run bills a month (monthly heads, "monthly:2026-03"), a session's
 *   once-a-session heads ("session:<id>") or chosen other heads
 *   ("other:<run>") to everyone studying at a campus (or a class or a
 *   section): draft bills first, checked, recalculated if structures or
 *   discounts changed, then made final (numbered, open) or cancelled.
 * - A student is billed once per key: a second run of the same month skips
 *   them; a cancelled bill frees the key.
 * - A new student is billed the "on admission" heads at once (rule
 *   education_fees.bill_on_admission).
 * - Amounts come from the most specific active structure; discounts from the
 *   student's concessions that day and the sibling discount; tax from the
 *   head's tax code when the institution keeps books.
 * - An open bill nobody paid anything on is cancelled with a reason.
 */
class Billing
{
    public function __construct(
        private FeeOffice $office,
        private FeeSetup $setup,
        private Concessions $concessions,
        private AcademicDirectory $academic,
        private FeeNumbers $numbers,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private ModuleResolver $modules,
        private OrganizationSettingsResolver $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * A draft run with its draft bills.
     *
     * @param  array<string, mixed>  $data  kind, session_id, period?, level_id?, section_id?, head_ids?, issue_date, due_date?, note?, op_id?
     * @return array{run: FeeRun, skipped: array{billed: int, no_amounts: int}}
     */
    public function createRun(Organization $company, Organization $unit, array $data, ?User $actor): array
    {
        if (($data['op_id'] ?? null) !== null && ($done = $this->office->query(FeeRun::class, $company)->where('op_id', $data['op_id'])->first()) !== null) {
            return ['run' => $done, 'skipped' => ['billed' => 0, 'no_amounts' => 0]];
        }
        $session = $this->academic->session($company, $data['session_id']) ?? throw FeeException::notFound('session');
        if ($data['kind'] === 'monthly') {
            $month = $data['period'];
            if ($month.'-31' < $session['starts_on'] || $month.'-01' > $session['ends_on']) {
                throw FeeException::periodOutsideSession($month);
            }
            $data['due_date'] ??= FeeMath::dueInMonth($month, (int) $this->rules->get('education_fees.due_day', $this->contexts->forOrganization($unit)));
        }
        $heads = $this->headsFor($company, $data['kind'], $data['head_ids'] ?? []);

        return $this->office->transaction($company, function () use ($company, $unit, $data, $heads, $actor) {
            $run = new FeeRun;
            $run->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'session_id' => $data['session_id'], 'kind' => $data['kind'],
                'period' => $data['kind'] === 'monthly' ? $data['period'] : null, 'level_id' => $data['level_id'] ?? null, 'section_id' => $data['section_id'] ?? null,
                'head_ids' => $data['kind'] === 'other' ? $heads->pluck('id')->values()->all() : null, 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'],
                'status' => 'draft', 'note' => $data['note'] ?? null, 'created_by' => $actor?->getKey(), 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ]);
            $run->save();
            $skipped = $this->fill($company, $run, $heads, $actor);
            $this->audit->record('education_fees.run_created', $run, new: $run->only(['kind', 'session_id', 'period', 'level_id', 'section_id', 'bills_count', 'total_minor']), actor: $actor, organizationId: $company->getKey());

            return ['run' => $run, 'skipped' => $skipped];
        });
    }

    /**
     * A draft run made again from today's structures, discounts and students.
     *
     * @return array{run: FeeRun, skipped: array{billed: int, no_amounts: int}}
     */
    public function refreshRun(Organization $company, FeeRun $run, int $baseVersion, User $actor): array
    {
        return $this->office->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->lockedRun($company, $run, $baseVersion, ['draft']);
            $this->dropDrafts($company, $run);
            $skipped = $this->fill($company, $run, $this->headsFor($company, $run->kind, $run->head_ids ?? []), $actor);
            $run->forceFill(['version' => $run->version + 1])->save();
            $this->audit->record('education_fees.run_refreshed', $run, new: $run->only(['bills_count', 'total_minor']), actor: $actor, organizationId: $company->getKey());

            return ['run' => $run, 'skipped' => $skipped];
        });
    }

    /** The run's bills get numbers and are open (a bill with nothing owed is paid at once). */
    public function finalizeRun(Organization $company, FeeRun $run, int $baseVersion, ?User $actor): FeeRun
    {
        return $this->office->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->lockedRun($company, $run, $baseVersion, ['draft']);
            $bills = $this->office->query(Bill::class, $company)->where('run_id', $run->getKey())->where('status', 'draft')->orderBy('student_id')->lockForUpdate()->get();
            foreach ($bills as $bill) {
                $this->issue($company, $bill);
            }
            $run->forceFill(['status' => 'final', 'finalized_by' => $actor?->getKey(), 'finalized_at' => now(), 'version' => $run->version + 1])->save();
            $this->audit->record('education_fees.run_finalized', $run, old: ['status' => 'draft'], new: ['status' => 'final', 'bills_count' => $run->bills_count, 'total_minor' => $run->total_minor], actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /** A draft run is dropped with its draft bills (nothing was issued). */
    public function cancelRun(Organization $company, FeeRun $run, int $baseVersion, User $actor): FeeRun
    {
        return $this->office->transaction($company, function () use ($company, $run, $baseVersion, $actor) {
            $run = $this->lockedRun($company, $run, $baseVersion, ['draft']);
            $this->dropDrafts($company, $run);
            $run->forceFill(['status' => 'cancelled', 'bills_count' => 0, 'total_minor' => 0, 'version' => $run->version + 1])->save();
            $this->audit->record('education_fees.run_cancelled', $run, old: ['status' => 'draft'], new: ['status' => 'cancelled'], actor: $actor, organizationId: $company->getKey());

            return $run;
        });
    }

    /** A new student's "on admission" heads, billed and issued at once (nothing when the rule is off or nothing applies). */
    public function billAdmission(Organization $company, string $studentId): ?Bill
    {
        $student = $this->academic->student($company, $studentId);
        $enrollment = $student === null ? null : $this->academic->enrollment($company, $studentId);
        if ($enrollment === null) {
            return null;
        }
        $unit = Organization::query()->find($enrollment['unit_id']) ?? $company;
        if (! $this->rules->get('education_fees.bill_on_admission', $this->contexts->forOrganization($unit))) {
            return null;
        }
        $heads = $this->office->query(FeeHead::class, $company)->where('frequency', 'admission')->where('is_active', true)->orderBy('sort_order')->get();
        if ($heads->isEmpty()) {
            return null;
        }
        $today = $this->today($company);

        return $this->office->transaction($company, function () use ($company, $student, $enrollment, $heads, $today) {
            $bill = $this->billFor($company, null, $student, $enrollment, $heads, 'admission', $today, $today, 'admission', null);
            if ($bill === null) {
                return null;
            }
            $this->issue($company, $bill);
            $this->audit->record('education_fees.bill_issued', $bill, new: $bill->only(['number', 'student_id', 'total_minor', 'source']), organizationId: $company->getKey());

            return $bill;
        });
    }

    /** An open bill with nothing paid on it, cancelled with a reason; its key may be billed again. */
    public function cancelBill(Organization $company, Bill $bill, string $reason, int $baseVersion, User $actor): Bill
    {
        return $this->office->transaction($company, function () use ($company, $bill, $reason, $baseVersion, $actor) {
            /** @var Bill $bill */
            $bill = $this->office->query(Bill::class, $company)->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            if ($bill->version !== $baseVersion) {
                throw FeeException::versionConflict(['version' => $bill->version]);
            }
            if (! in_array($bill->status, ['open', 'paid'], true)) {
                throw FeeException::wrongStatus($bill->status);
            }
            if ($bill->paid_minor > 0) {
                throw FeeException::billPaid();
            }
            $old = $bill->status;
            $bill->forceFill(['status' => 'cancelled', 'active_key' => null, 'cancel_reason' => $reason, 'version' => $bill->version + 1])->save();
            $this->audit->record('education_fees.bill_cancelled', $bill, old: ['status' => $old], new: ['status' => 'cancelled', 'total_minor' => $bill->total_minor], reason: $reason, actor: $actor, organizationId: $company->getKey());
            DB::afterCommit(fn () => event(new BillCancelled($company->getKey(), $bill->getKey(), $bill->student_id)));

            return $bill;
        });
    }

    /** @return Collection<int, BillLine> */
    public function lines(Organization $company, Bill $bill): Collection
    {
        return $this->office->query(BillLine::class, $company)->where('bill_id', $bill->getKey())->orderBy('created_at')->orderBy('id')->get();
    }

    /** Today at the institution. */
    public function today(Organization $company): string
    {
        return $this->office->today($company)->toDateString();
    }

    /**
     * Draft bills for everyone the run covers; the run keeps the count and total.
     *
     * @param  Collection<int, FeeHead>  $heads
     * @return array{billed: int, no_amounts: int}
     */
    private function fill(Organization $company, FeeRun $run, Collection $heads, ?User $actor): array
    {
        $unit = Organization::query()->findOrFail($run->unit_id);
        $rows = $this->academic->enrolledStudents($company, $run->session_id, $this->office->subtreeIds($unit), $run->level_id, $run->section_id);
        $key = match ($run->kind) {
            'monthly' => "monthly:{$run->period}",
            'session' => "session:{$run->session_id}",
            default => "other:{$run->getKey()}",
        };
        $taken = $this->office->query(Bill::class, $company)->whereIn('active_key', array_map(fn (array $row) => $row['student']['id'].':'.$key, $rows))->pluck('active_key')->flip();
        $context = new BillingContext(
            structures: $this->setup->activeStructures($company, $run->session_id),
            concessions: $this->concessions->on($company, array_map(fn (array $row) => $row['student']['id'], $rows), $run->issue_date->toDateString()),
            places: $heads->contains('sibling_discount', true) ? $this->academic->siblingPlaces($company, array_map(fn (array $row) => $row['student']['id'], $rows)) : [],
            taxRates: $this->taxRates($company, $heads),
            month: $run->kind === 'monthly' ? (int) substr((string) $run->period, 5, 2) : null,
        );

        $count = 0;
        $total = 0;
        $skipped = ['billed' => 0, 'no_amounts' => 0];
        foreach ($rows as $row) {
            if (isset($taken[$row['student']['id'].':'.$key])) {
                $skipped['billed']++;

                continue;
            }
            $bill = $this->billFor($company, $run, $row['student'], $row['enrollment'], $heads, $key, $run->issue_date->toDateString(), $run->due_date->toDateString(), 'run', $actor, $context);
            if ($bill === null) {
                $skipped['no_amounts']++;

                continue;
            }
            $count++;
            $total += $bill->total_minor;
        }
        if ($count === 0) {
            throw FeeException::nothingToBill();
        }
        $run->forceFill(['bills_count' => $count, 'total_minor' => $total])->save();

        return $skipped;
    }

    /**
     * One student's draft bill for the heads that have an amount for them (null: none has).
     *
     * @param  array<string, mixed>  $student
     * @param  array<string, mixed>  $enrollment
     * @param  Collection<int, FeeHead>  $heads
     */
    private function billFor(Organization $company, ?FeeRun $run, array $student, array $enrollment, Collection $heads, string $key, string $issueDate, string $dueDate, string $source, ?User $actor, ?BillingContext $context = null): ?Bill
    {
        $context ??= new BillingContext(
            structures: $this->setup->activeStructures($company, $enrollment['session_id']),
            concessions: $this->concessions->on($company, [$student['id']], $issueDate),
            places: $heads->contains('sibling_discount', true) ? $this->academic->siblingPlaces($company, [$student['id']]) : [],
            taxRates: $this->taxRates($company, $heads),
            month: null,
        );
        $amounts = FeeMath::amountsFor($context->structures, [
            'unit_id' => $enrollment['unit_id'], 'program_id' => $student['program_id'], 'level_id' => $enrollment['level_id'], 'category_id' => $student['category_id'] ?? null,
        ]);
        $unit = Organization::query()->find($enrollment['unit_id']) ?? $company;
        $siblingBp = 100 * (int) $this->rules->get('education_fees.sibling_discount_percent', $this->contexts->forOrganization($unit));
        $inclusive = (bool) $this->rules->get('education_fees.fees_include_tax', $this->contexts->forOrganization($company));
        /** @var Collection<int, Concession> $concessions */
        $concessions = collect($context->concessions[$student['id']] ?? []);
        // A fixed amount off "all heads" is used up line by line.
        $sharedFixed = (int) $concessions->where('mode', 'fixed')->whereNull('head_id')->sum('amount_minor');

        $lines = [];
        foreach ($heads as $head) {
            $amount = $amounts[$head->getKey()] ?? null;
            if ($amount === null || $amount['amount_minor'] <= 0 || ($context->month !== null && $amount['months'] !== null && ! in_array($context->month, array_map('intval', $amount['months']), true))) {
                continue;
            }
            $own = $concessions->filter(fn (Concession $concession) => $concession->head_id === null || $concession->head_id === $head->getKey());
            $percentBp = (int) $own->where('mode', 'percent')->sum('percent_bp');
            $sibling = $head->sibling_discount && $siblingBp > 0 && ($context->places[$student['id']] ?? 1) >= 2 ? $siblingBp : 0;
            $fixed = (int) $own->where('mode', 'fixed')->whereNotNull('head_id')->sum('amount_minor');
            $beforeShared = FeeMath::line($amount['amount_minor'], $percentBp + $sibling, $fixed);
            $shared = min($sharedFixed, $amount['amount_minor'] - $beforeShared['discount_minor']);
            $sharedFixed -= $shared;
            $result = FeeMath::line($amount['amount_minor'], $percentBp + $sibling, $fixed + $shared, $context->taxRates[$head->tax_code_id ?? ''] ?? 0, $inclusive);
            $lines[] = [
                'head_id' => $head->getKey(), 'amount_minor' => $amount['amount_minor'], ...$result,
                'tax_code_id' => isset($context->taxRates[$head->tax_code_id ?? '']) ? $head->tax_code_id : null,
                'basis' => array_filter([
                    'structure_id' => $amount['structure_id'],
                    'concessions' => $own->pluck('id')->values()->all() ?: null,
                    'percent_bp' => $percentBp ?: null,
                    'sibling_bp' => $sibling ?: null,
                    'fixed_minor' => ($fixed + $shared) ?: null,
                ]),
            ];
        }
        if ($lines === []) {
            return null;
        }

        $bill = new Bill;
        $bill->fill([
            'organization_id' => $company->getKey(), 'unit_id' => $enrollment['unit_id'], 'student_id' => $student['id'], 'session_id' => $enrollment['session_id'],
            'run_id' => $run?->getKey(), 'billing_key' => $key, 'active_key' => $student['id'].':'.$key, 'issue_date' => $issueDate, 'due_date' => $dueDate,
            'status' => 'draft', 'currency' => $this->currency($company), 'source' => $source, 'created_by' => $actor?->getKey(), 'version' => 1,
            'gross_minor' => array_sum(array_column($lines, 'amount_minor')), 'discount_minor' => array_sum(array_column($lines, 'discount_minor')),
            'tax_minor' => array_sum(array_column($lines, 'tax_minor')), 'fine_minor' => 0, 'total_minor' => array_sum(array_column($lines, 'due_minor')), 'paid_minor' => 0,
        ]);
        $bill->save();
        foreach ($lines as $line) {
            (new BillLine)->fill(['organization_id' => $company->getKey(), 'bill_id' => $bill->getKey(), ...$line])->save();
        }

        return $bill;
    }

    /** A draft bill gets its number and is open (paid when nothing is owed). */
    private function issue(Organization $company, Bill $bill): void
    {
        $bill->forceFill([
            'number' => $this->numbers->bill($company, (int) $bill->issue_date->format('Y')),
            'status' => $bill->total_minor > 0 ? 'open' : 'paid',
            'version' => $bill->version + 1,
        ])->save();
        DB::afterCommit(fn () => event(new BillIssued($company->getKey(), $bill->getKey(), $bill->student_id)));
    }

    /** @return Collection<int, FeeHead> */
    private function headsFor(Organization $company, string $kind, array $headIds): Collection
    {
        $active = $this->office->query(FeeHead::class, $company)->where('is_active', true)->orderBy('sort_order')->orderBy('code');
        if ($kind !== 'other') {
            return $active->where('frequency', $kind)->get();
        }
        $heads = $active->whereKey($headIds)->get();
        if ($heads->count() !== count(array_unique($headIds))) {
            throw FeeException::notFound('head');
        }
        foreach ($heads as $head) {
            if ($head->frequency !== 'other') {
                throw FeeException::headNotForRun($head->code);
            }
        }

        return $heads;
    }

    private function dropDrafts(Organization $company, FeeRun $run): void
    {
        $drafts = $this->office->query(Bill::class, $company)->where('run_id', $run->getKey())->where('status', 'draft')->pluck('id');
        $this->office->query(BillLine::class, $company)->whereIn('bill_id', $drafts)->delete();
        $this->office->query(Bill::class, $company)->whereKey($drafts)->delete();
        $run->forceFill(['bills_count' => 0, 'total_minor' => 0]);
    }

    /** @param  list<string>  $statuses */
    private function lockedRun(Organization $company, FeeRun $run, int $baseVersion, array $statuses): FeeRun
    {
        /** @var FeeRun $fresh */
        $fresh = $this->office->query(FeeRun::class, $company)->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw FeeException::versionConflict(['version' => $fresh->version]);
        }
        if (! in_array($fresh->status, $statuses, true)) {
            throw FeeException::wrongStatus($fresh->status);
        }

        return $fresh;
    }

    /**
     * Sales tax rates of the heads' tax codes, when the institution keeps books (else no tax).
     *
     * @param  Collection<int, FeeHead>  $heads
     * @return array<string, int>
     */
    private function taxRates(Organization $company, Collection $heads): array
    {
        $codes = $heads->pluck('tax_code_id')->filter()->unique()->values()->all();
        if ($codes === [] || ! $this->modules->isEnabled('accounting', $company)) {
            return [];
        }

        return app(TaxCodes::class)->salesRates($company, $codes);
    }

    private function currency(Organization $company): string
    {
        return $this->settings->values($company)['currency_code'] ?? throw FeeException::noCurrency();
    }
}
