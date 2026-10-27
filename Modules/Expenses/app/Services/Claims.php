<?php

namespace Modules\Expenses\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Models\Approval;
use Modules\Expenses\Models\Category;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Models\Line;
use Modules\Expenses\Models\Receipt;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * An employee's expense claims: a draft (lines, receipts) until submitted;
 * then approved step by step (the employee's manager first, the finance
 * people second when the total is above expenses.second_approval_above);
 * approved claims go into the books (each line's kind against "owed to
 * employees") and are paid back in cash or by bank now, or with a month's
 * salary (Payroll pays the month, then the claim is paid). Nobody decides
 * a claim of their own. Approved and paid claims are never changed.
 */
class Claims
{
    public function __construct(
        private Place $place,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private OrganizationSettingsResolver $settings,
        private ModuleResolver $modules,
        private Postings $postings,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * A new draft (or the one sent before with the same op id).
     *
     * @param  array{title: string, lines: list<array<string, mixed>>, op_id?: string|null}  $data
     */
    public function draft(Organization $company, EmployeeRecord $employee, array $data, User $actor): Claim
    {
        if (! empty($data['op_id'])) {
            $same = $this->place->query(Claim::class, $company)->where('op_id', $data['op_id'])->first();
            if ($same !== null) {
                return $same;
            }
        }
        $lines = $this->checkedLines($company, $data['lines']);
        // An advance it settles: the employee's own, paid and not settled yet.
        $advance = empty($data['advance_id']) ? null : app(Advances::class)->openFor($company, $employee->id, $data['advance_id']);

        return $this->place->transaction($company, function () use ($company, $employee, $data, $lines, $actor, $advance) {
            $claim = new Claim;
            $claim->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id,
                'title' => trim($data['title']), 'currency' => $this->currency($company), 'status' => Claim::DRAFT,
                'requested_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null, 'advance_id' => $advance?->getKey(), 'version' => 1,
            ])->save();
            $this->writeLines($company, $claim, $lines);

            return $claim;
        });
    }

    /**
     * A draft's title and lines, with the version seen. Lines sent with their id stay (their receipts too);
     * lines left out go (their receipts stay on the claim).
     *
     * @param  array{title?: string, lines?: list<array<string, mixed>>, base_version: int}  $data
     */
    public function update(Organization $company, Claim $claim, array $data): Claim
    {
        $lines = isset($data['lines']) ? $this->checkedLines($company, $data['lines']) : null;

        return $this->place->transaction($company, function () use ($company, $claim, $data, $lines) {
            $claim = $this->fresh($company, $claim, (int) $data['base_version']);
            if ($claim->status !== Claim::DRAFT) {
                throw ExpensesException::notDraft();
            }
            if (isset($data['title'])) {
                $claim->title = trim($data['title']);
            }
            if (array_key_exists('advance_id', $data)) {
                $claim->advance_id = empty($data['advance_id']) ? null : app(Advances::class)->openFor($company, $claim->employee_id, $data['advance_id'])->getKey();
            }
            if ($lines !== null) {
                $this->writeLines($company, $claim, $lines);
            }
            $claim->forceFill(['version' => $claim->version + 1])->save();

            return $claim;
        });
    }

    /** A draft nobody needs any more goes (its receipts too). */
    public function discard(Organization $company, Claim $claim, int $baseVersion): void
    {
        $this->place->transaction($company, function () use ($company, $claim, $baseVersion) {
            $claim = $this->fresh($company, $claim, $baseVersion);
            if ($claim->status !== Claim::DRAFT) {
                throw ExpensesException::notDraft();
            }
            foreach ($this->place->query(Receipt::class, $company)->where('claim_id', $claim->getKey())->get() as $receipt) {
                app(Receipts::class)->remove($company, $claim, $receipt);
            }
            $this->place->query(Line::class, $company)->where('claim_id', $claim->getKey())->delete();
            $claim->delete();
        });
    }

    /** Sent for approval: lines above expenses.receipt_required_above need a receipt; it gets its number. */
    public function submit(Organization $company, Claim $claim, int $baseVersion, User $actor): Claim
    {
        return $this->place->transaction($company, function () use ($company, $claim, $baseVersion, $actor) {
            $claim = $this->fresh($company, $claim, $baseVersion);
            if ($claim->status !== Claim::DRAFT) {
                throw ExpensesException::notDraft();
            }
            $lines = $this->place->query(Line::class, $company)->where('claim_id', $claim->getKey())->orderBy('line_no')->get();
            if ($lines->isEmpty() || $claim->total_minor <= 0) {
                throw ExpensesException::emptyClaim();
            }
            $context = $this->contexts->forOrganization($company);
            $needs = (int) $this->rules->get('expenses.receipt_required_above', $context);
            $withReceipt = $this->place->query(Receipt::class, $company)->where('claim_id', $claim->getKey())->pluck('line_id')->filter()->all();
            foreach ($lines as $line) {
                if ($line->distance_tenths === null && $line->per_diem_code === null && $line->amount_minor > $needs && ! in_array($line->getKey(), $withReceipt, true)) {
                    throw ExpensesException::receiptNeeded($line->line_no);
                }
            }
            $second = (int) $this->rules->get('expenses.second_approval_above', $context);
            $claim->forceFill([
                'status' => Claim::SUBMITTED, 'submitted_at' => now(), 'number' => $this->nextNumber($company),
                'steps_needed' => $claim->total_minor > $second ? 2 : 1, 'steps_done' => 0, 'version' => $claim->version + 1,
            ])->save();
            $this->audit->record('expenses.submitted', $claim, new: $this->values($claim), actor: $actor, organizationId: $company->getKey());
            $employee = $this->directory->find($company, $claim->employee_id);
            $this->tell('expenses.submitted', $company, $claim, $this->approvers($company, $claim)
                ->reject(fn (User $user) => $user->getKey() === $employee?->userId || $user->getKey() === $actor->getKey()));

            return $claim;
        });
    }

    /** Taken back by the one who sent it, before anyone decided. */
    public function withdraw(Organization $company, Claim $claim, int $baseVersion, User $actor): Claim
    {
        return $this->place->transaction($company, function () use ($company, $claim, $baseVersion, $actor) {
            $claim = $this->fresh($company, $claim, $baseVersion);
            if ($claim->status !== Claim::SUBMITTED || $claim->steps_done > 0) {
                throw ExpensesException::notPayable();
            }
            $claim->forceFill(['status' => Claim::WITHDRAWN, 'version' => $claim->version + 1])->save();
            $this->audit->record('expenses.withdrawn', $claim, new: $this->values($claim), actor: $actor, organizationId: $company->getKey());

            return $claim;
        });
    }

    /** Whether this person may decide the claim's next step now. */
    public function canDecide(Organization $company, Claim $claim, User $actor): bool
    {
        if ($claim->status !== Claim::SUBMITTED) {
            return false;
        }
        $decided = $this->place->query(Approval::class, $company)->where('claim_id', $claim->getKey())->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        return app(Deciders::class)->canDecide($company, $claim->unit_id, $claim->employee_id, $claim->requested_by, $claim->steps_done, $decided, $actor);
    }

    public function approve(Organization $company, Claim $claim, int $baseVersion, ?string $note, User $actor): Claim
    {
        return $this->place->transaction($company, function () use ($company, $claim, $baseVersion, $note, $actor) {
            $claim = $this->deciding($company, $claim, $baseVersion, $actor);
            $step = $claim->steps_done + 1;
            $this->record($company, $claim, $step, 'approved', $note, $actor);
            $done = $step >= $claim->steps_needed;
            $claim->forceFill(['steps_done' => $step, 'version' => $claim->version + 1])->save();
            if (! $done) {
                $this->audit->record('expenses.step_approved', $claim, new: [...$this->values($claim), 'step' => $step], reason: $note, actor: $actor, organizationId: $company->getKey());
                $this->tell('expenses.submitted', $company, $claim, $this->approvers($company, $claim)->reject(fn (User $user) => $user->getKey() === $actor->getKey()));

                return $claim;
            }
            $journal = $this->postings->post($company, "expense-claim-{$claim->getKey()}", $this->place->today($company),
                __('expenses::expenses.narration.claim', ['number' => $claim->number]), 'claim', $claim->getKey(), $claim->currency, $this->claimLines($company, $claim));
            $claim->forceFill(['status' => Claim::APPROVED, 'decided_at' => now(), 'decided_by' => $actor->getKey(), 'decision_note' => $note, 'journal_id' => $journal])->save();
            $this->audit->record('expenses.approved', $claim, new: [...$this->values($claim), 'journal_id' => $journal], reason: $note, actor: $actor, organizationId: $company->getKey());
            $this->tell('expenses.decided', $company, $claim, $this->claimant($company, $claim), $note);

            return $claim;
        });
    }

    public function reject(Organization $company, Claim $claim, int $baseVersion, string $note, User $actor): Claim
    {
        return $this->place->transaction($company, function () use ($company, $claim, $baseVersion, $note, $actor) {
            $claim = $this->deciding($company, $claim, $baseVersion, $actor);
            $this->record($company, $claim, $claim->steps_done + 1, 'rejected', $note, $actor);
            $claim->forceFill(['status' => Claim::REJECTED, 'decided_at' => now(), 'decided_by' => $actor->getKey(), 'decision_note' => $note, 'version' => $claim->version + 1])->save();
            $this->audit->record('expenses.rejected', $claim, new: $this->values($claim), reason: $note, actor: $actor, organizationId: $company->getKey());
            $this->tell('expenses.decided', $company, $claim, $this->claimant($company, $claim), $note);

            return $claim;
        });
    }

    /**
     * Paying an approved claim back: in cash or by bank now (into the books: owed to employees against cash
     * or bank), or with a month's salary (from this month on; Payroll pays it with that month and the claim is
     * then paid). A claim waiting for its month may be moved to another month or paid now instead.
     *
     * @param  array{method: string, period?: string|null, base_version: int}  $data
     */
    public function pay(Organization $company, Claim $claim, array $data, User $actor): Claim
    {
        return $this->place->transaction($company, function () use ($company, $claim, $data, $actor) {
            $claim = $this->fresh($company, $claim, (int) $data['base_version']);
            if ($claim->status !== Claim::APPROVED) {
                throw ExpensesException::notPayable();
            }
            // The advance it settles goes first (owed to the employee against the advance, as far as it goes).
            if ($claim->advance_id !== null && $claim->advance_minor === 0) {
                $claim->advance_minor = app(Advances::class)->settleByClaim($company, $claim, $actor);
            }
            $rest = $claim->total_minor - $claim->advance_minor;
            if ($rest <= 0) {
                $claim->forceFill(['status' => Claim::PAID, 'pay_method' => 'advance', 'pay_period' => null, 'paid_at' => now(), 'paid_by' => $actor->getKey(), 'version' => $claim->version + 1])->save();
                $this->audit->record('expenses.paid', $claim, new: $this->values($claim), actor: $actor, organizationId: $company->getKey());
                $this->tell('expenses.paid', $company, $claim, $this->claimant($company, $claim));

                return $claim;
            }
            if ($data['method'] === 'payroll') {
                if (! $this->modules->isEnabled('payroll', $company)) {
                    throw ExpensesException::payrollOff();
                }
                $period = (string) ($data['period'] ?? '');
                if ($period < $this->place->today($company)->format('Y-m')) {
                    throw ValidationException::withMessages(['period' => __('expenses::expenses.validation.period_past')]);
                }
                $claim->forceFill(['pay_method' => 'payroll', 'pay_period' => $period, 'version' => $claim->version + 1])->save();
                $this->audit->record('expenses.scheduled', $claim, new: [...$this->values($claim), 'period' => $period], actor: $actor, organizationId: $company->getKey());

                return $claim;
            }
            $account = $data['method'] === 'bank' ? 'expenses.bank_account' : 'expenses.cash_account';
            $journal = $this->postings->post($company, "expense-payment-{$claim->getKey()}", $this->place->today($company),
                __('expenses::expenses.narration.payment', ['number' => $claim->number]), 'payment', $claim->getKey(), $claim->currency,
                [LedgerLine::debit('expenses.claims_payable', $rest), LedgerLine::credit($account, $rest)]);
            $claim->forceFill([
                'status' => Claim::PAID, 'pay_method' => $data['method'], 'pay_period' => null, 'paid_at' => now(), 'paid_by' => $actor->getKey(),
                'payment_journal_id' => $journal, 'version' => $claim->version + 1,
            ])->save();
            $this->audit->record('expenses.paid', $claim, new: [...$this->values($claim), 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());
            $this->tell('expenses.paid', $company, $claim, $this->claimant($company, $claim));

            return $claim;
        });
    }

    /**
     * Payroll approved a month: the claims waiting for that month's salary are paid with it
     * (Payroll's books took them from "owed to employees"). How many.
     */
    public function paidWithPayroll(Organization $company, string $period, string $runId): int
    {
        $claims = $this->place->query(Claim::class, $company)->where('status', Claim::APPROVED)->where('pay_method', 'payroll')->where('pay_period', $period)->get();
        foreach ($claims as $claim) {
            $claim->forceFill(['status' => Claim::PAID, 'pay_run_id' => $runId, 'paid_at' => now(), 'version' => $claim->version + 1])->save();
            $this->audit->record('expenses.paid', $claim, new: [...$this->values($claim), 'run_id' => $runId], organizationId: $company->getKey());
            $this->tell('expenses.paid', $company, $claim, $this->claimant($company, $claim));
        }

        return $claims->count();
    }

    /**
     * The claims waiting for a month's salary, per employee (Payroll adds them to the slips).
     *
     * @param  list<string>  $employeeIds
     * @return array<string, int>
     */
    public function dueWithSalary(Organization $company, array $employeeIds, string $period): array
    {
        return $this->place->query(Claim::class, $company)->where('status', Claim::APPROVED)->where('pay_method', 'payroll')
            ->where('pay_period', $period)->whereIn('employee_id', $employeeIds)->get(['employee_id', 'total_minor', 'advance_minor'])
            // Only what an advance did not settle is paid with the salary.
            ->groupBy('employee_id')->map(fn (Collection $claims) => (int) $claims->sum(fn (Claim $claim) => $claim->total_minor - $claim->advance_minor))->all();
    }

    /** The people who may decide the claim's next step (for telling them). */
    public function approvers(Organization $company, Claim $claim): Collection
    {
        return app(Deciders::class)->approvers($company, $claim->unit_id, $claim->employee_id, $claim->steps_done);
    }

    /**
     * Checked lines of a claim: a kind of the company still in use, a day not in the future nor older than
     * expenses.max_claim_age_days, an amount, or kilometres for a mileage kind (at expenses.mileage_rate).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function checkedLines(Organization $company, array $lines): array
    {
        $context = $this->contexts->forOrganization($company);
        $today = $this->place->today($company);
        $oldest = $today->subDays((int) $this->rules->get('expenses.max_claim_age_days', $context));
        $rate = (int) $this->rules->get('expenses.mileage_rate', $context);
        $perDiem = collect((array) $this->rules->get('expenses.per_diem_rates', $context))->keyBy('code');
        $categories = $this->place->query(Category::class, $company)->whereKey(array_column($lines, 'category_id'))->get()->keyBy(fn (Category $category) => $category->getKey());
        $errors = [];
        $checked = [];
        foreach (array_values($lines) as $index => $line) {
            $category = $categories[$line['category_id']] ?? null;
            if ($category === null) {
                throw ExpensesException::categoryNotFound();
            }
            if (! $category->is_active) {
                throw ExpensesException::categoryClosed();
            }
            $day = CarbonImmutable::parse($line['spent_on'], 'UTC');
            if ($day->greaterThan($today)) {
                $errors["lines.{$index}.spent_on"] = __('expenses::expenses.validation.future');
            } elseif ($day->lessThan($oldest)) {
                $errors["lines.{$index}.spent_on"] = __('expenses::expenses.validation.too_old', ['days' => $today->diffInDays($oldest)]);
            }
            $distance = null;
            $code = null;
            $days = null;
            $amount = (int) ($line['amount_minor'] ?? 0);
            if ($category->per_diem) {
                // Days at a daily rate of the rule (by kind of place), no receipt needed.
                $code = (string) ($line['per_diem_code'] ?? '');
                $days = (int) ($line['days'] ?? 0);
                $daily = (int) ($perDiem[$code]['amount_minor'] ?? 0);
                if ($daily <= 0) {
                    $errors["lines.{$index}.per_diem_code"] = __('expenses::expenses.validation.per_diem_rate');
                }
                if ($days <= 0) {
                    $errors["lines.{$index}.days"] = __('expenses::expenses.validation.days');
                }
                $amount = $days * $daily;
            } elseif ($category->mileage) {
                $distance = (int) ($line['distance_tenths'] ?? 0);
                if ($distance <= 0 || $rate <= 0) {
                    $errors["lines.{$index}.distance_tenths"] = __($rate <= 0 ? 'expenses::expenses.validation.no_mileage_rate' : 'expenses::expenses.validation.distance');
                }
                // Kilometres in tenths at a rate per kilometre, rounded half up to the minor unit.
                $amount = intdiv($distance * $rate + 5, 10);
            } elseif ($amount <= 0) {
                $errors["lines.{$index}.amount_minor"] = __('expenses::expenses.validation.amount');
            }
            $checked[] = [
                'id' => $line['id'] ?? null, 'spent_on' => $day->toDateString(), 'category_id' => $category->getKey(),
                'description' => trim((string) $line['description']), 'amount_minor' => $amount, 'distance_tenths' => $distance,
                'per_diem_code' => $code, 'days' => $days,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Organization $company, Claim $claim, array $lines): void
    {
        $existing = $this->place->query(Line::class, $company)->where('claim_id', $claim->getKey())->get()->keyBy(fn (Line $line) => $line->getKey());
        $kept = [];
        foreach ($lines as $index => $data) {
            $line = $data['id'] !== null && isset($existing[$data['id']]) ? $existing[$data['id']] : new Line(['organization_id' => $company->getKey(), 'claim_id' => $claim->getKey()]);
            $line->fill([...array_diff_key($data, ['id' => true]), 'line_no' => $index + 1])->save();
            $kept[] = $line->getKey();
        }
        $gone = $existing->keys()->diff($kept)->all();
        if ($gone !== []) {
            $this->place->query(Receipt::class, $company)->whereIn('line_id', $gone)->update(['line_id' => null]);
            $this->place->query(Line::class, $company)->whereKey($gone)->delete();
        }
        $claim->forceFill(['total_minor' => array_sum(array_column($lines, 'amount_minor'))])->save();
    }

    /**
     * Into the books when approved: each kind's posting key (the employee's unit as cost centre) against owed to employees.
     *
     * @return list<LedgerLine>
     */
    private function claimLines(Organization $company, Claim $claim): array
    {
        $lines = $this->place->query(Line::class, $company)->where('claim_id', $claim->getKey())->get();
        $keys = $this->place->query(Category::class, $company)->whereKey($lines->pluck('category_id')->unique()->all())->pluck('posting_key', 'id');
        $posted = [];
        foreach ($lines->groupBy(fn (Line $line) => $keys[$line->category_id] ?? 'expenses.other') as $key => $group) {
            $posted[] = LedgerLine::debit((string) $key, (int) $group->sum('amount_minor'), $claim->unit_id);
        }
        $posted[] = LedgerLine::credit('expenses.claims_payable', $claim->total_minor);

        return $posted;
    }

    /** "EXP-2026-00001": per company and year, the next free number. */
    private function nextNumber(Organization $company): string
    {
        $prefix = 'EXP-'.$this->place->today($company)->format('Y').'-';
        $last = $this->place->query(Claim::class, $company)->where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')->max();

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }

    /** The company's currency: claims are in it. */
    public function currency(Organization $company): string
    {
        return (string) ($this->settings->values($company)['currency_code'] ?? 'BDT');
    }

    private function deciding(Organization $company, Claim $claim, int $baseVersion, User $actor): Claim
    {
        $claim = $this->fresh($company, $claim, $baseVersion);
        if (! $this->canDecide($company, $claim, $actor)) {
            throw ExpensesException::cannotDecide();
        }

        return $claim;
    }

    private function fresh(Organization $company, Claim $claim, int $baseVersion): Claim
    {
        /** @var Claim $fresh */
        $fresh = $this->place->query(Claim::class, $company)->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ExpensesException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    private function record(Organization $company, Claim $claim, int $step, string $decision, ?string $note, User $actor): void
    {
        (new Approval)->fill(['organization_id' => $company->getKey(), 'claim_id' => $claim->getKey(), 'step' => $step, 'user_id' => $actor->getKey(), 'decision' => $decision, 'note' => $note])->save();
    }

    private function claimant(Organization $company, Claim $claim): Collection
    {
        $userId = $this->directory->find($company, $claim->employee_id)?->userId;

        return $userId === null ? collect() : User::query()->whereKey($userId)->get();
    }

    private function tell(string $key, Organization $company, Claim $claim, Collection $to, ?string $note = null): void
    {
        if ($to->isEmpty()) {
            return;
        }
        $employee = $this->directory->find($company, $claim->employee_id);
        $this->notifier->notify(
            $key,
            $to->values(),
            fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'employee' => $employee?->name ?? '',
                'number' => (string) $claim->number,
                'amount' => number_format(intdiv($claim->total_minor, 100)).'.'.str_pad((string) ($claim->total_minor % 100), 2, '0', STR_PAD_LEFT).' '.$claim->currency,
                'decision' => __("expenses::expenses.status.{$claim->status}", [], $locale),
                'note' => (string) $note,
            ],
            $company->partner,
            $company,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Claim $claim): array
    {
        return $claim->only(['number', 'employee_id', 'total_minor', 'currency', 'status', 'steps_needed', 'steps_done', 'pay_method', 'pay_period']);
    }
}
