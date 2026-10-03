<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Enums\PeriodStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Period;

/**
 * Fiscal years and their monthly periods. The first year starts on the
 * company's fiscal year start (rule accounting.fiscal_year_start, a country
 * value: 07-01 in Bangladesh) unless the company names another date; every
 * later year follows the one before without a gap. A change of the rule
 * applies to years created afterwards.
 */
class FiscalCalendar
{
    /** Months in a fiscal year (one period each). */
    public const PERIODS = 12;

    public function __construct(
        private Books $books,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * Add the company's next fiscal year (or its first one).
     */
    public function addYear(Organization $company, ?string $startsOn, ?User $actor): FiscalYear
    {
        return $this->books->transaction($company, function () use ($company, $startsOn, $actor) {
            $last = $this->books->query(FiscalYear::class, $company)->orderByDesc('starts_on')->first();

            if ($last !== null) {
                $start = $last->ends_on->addDay();
                if ($startsOn !== null && $startsOn !== $start->toDateString()) {
                    throw AccountingException::yearNotNext($start->toDateString());
                }
            } else {
                $start = $startsOn !== null ? CarbonImmutable::parse($startsOn, 'UTC') : $this->currentYearStart($company);
            }

            $end = $start->addMonthsNoOverflow(self::PERIODS)->subDay();
            $year = new FiscalYear;
            $year->fill([
                'organization_id' => $company->getKey(),
                'name' => $start->format('m-d') === '01-01' ? $start->format('Y') : $start->format('Y').'-'.$end->format('y'),
                'starts_on' => $start,
                'ends_on' => $end,
                'status' => 'open',
            ])->save();

            for ($number = 1; $number <= self::PERIODS; $number++) {
                $period = new Period;
                $period->fill([
                    'organization_id' => $company->getKey(),
                    'fiscal_year_id' => $year->getKey(),
                    'number' => $number,
                    'starts_on' => $start->addMonthsNoOverflow($number - 1),
                    'ends_on' => $start->addMonthsNoOverflow($number)->subDay(),
                    'status' => PeriodStatus::Open,
                ])->save();
            }

            // The running journal number of the year exists from the start (no race to create it).
            $this->books->table('acc_sequences', $company)->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $company->getKey(), 'fiscal_year_id' => $year->getKey(),
                'last' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->audit->record('accounting.fiscal_year_created', $year, new: [
                'name' => $year->name, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
            ], actor: $actor, organizationId: $company->getKey());

            return $year;
        });
    }

    /** The start of the fiscal year that holds today, by the company's rule. */
    public function currentYearStart(Organization $company): CarbonImmutable
    {
        $today = $this->books->today($company);
        [$month, $day] = array_map('intval', explode('-', (string) $this->rules->get('accounting.fiscal_year_start', $this->contexts->forOrganization($company))));

        $start = $this->startIn($today->year, $month, $day);

        return $start->greaterThan($today) ? $this->startIn($today->year - 1, $month, $day) : $start;
    }

    /** The period that holds a date, or null when no fiscal year covers it. */
    public function periodFor(Organization $company, CarbonImmutable|string $date, bool $lock = false): ?Period
    {
        $day = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return $this->books->query(Period::class, $company)
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * The open period a journal dated $date posts into (locked until the
     * transaction ends, so nobody closes it meanwhile).
     */
    public function openPeriodFor(Organization $company, CarbonImmutable $date): Period
    {
        $period = $this->periodFor($company, $date, lock: true) ?? throw AccountingException::noPeriod($date->toDateString());

        if ($period->status !== PeriodStatus::Open) {
            throw AccountingException::periodClosed($period->starts_on->toDateString(), $period->ends_on->toDateString());
        }

        return $period;
    }

    public function close(Organization $company, Period $period, User $actor): Period
    {
        return $this->books->transaction($company, function () use ($company, $period, $actor) {
            $period = $this->locked($company, $period);
            if ($period->status === PeriodStatus::Closed) {
                throw AccountingException::periodAlreadyClosed();
            }

            $waiting = $this->books->query(Journal::class, $company)
                ->where('status', JournalStatus::PendingApproval->value)
                ->whereBetween('entry_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])
                ->count();
            if ($waiting > 0) {
                throw AccountingException::pendingInPeriod($waiting);
            }

            $period->forceFill(['status' => PeriodStatus::Closed, 'closed_by' => $actor->getKey(), 'closed_at' => now()])->save();
            $this->audit->record('accounting.period_closed', $period, new: [
                'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(),
            ], actor: $actor, organizationId: $company->getKey());

            return $period;
        });
    }

    public function reopen(Organization $company, Period $period, string $reason, User $actor): Period
    {
        return $this->books->transaction($company, function () use ($company, $period, $reason, $actor) {
            $period = $this->locked($company, $period);
            if ($period->status !== PeriodStatus::Closed) {
                throw AccountingException::periodNotClosed();
            }

            $period->forceFill(['status' => PeriodStatus::Open, 'closed_by' => null, 'closed_at' => null])->save();
            $this->audit->record('accounting.period_reopened', $period, new: [
                'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(),
            ], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $period;
        });
    }

    private function locked(Organization $company, Period $period): Period
    {
        return $this->books->query(Period::class, $company)->whereKey($period->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Day $day of a month, or its last day when the month is shorter (a 31st, or 29 February). */
    private function startIn(int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');

        return $first->setDay(min($day, $first->daysInMonth));
    }
}
