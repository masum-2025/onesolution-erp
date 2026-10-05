<?php

namespace Modules\Payroll\Services;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Models\BonusLine;
use Modules\Payroll\Models\BonusRun;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\PfEntry;
use Modules\Payroll\Models\Settlement;

/**
 * What employees see of their own loans and bonuses, in the app (through
 * their linked login) and in the client's portal: loans once approved,
 * the provident fund, final settlements once approved,
 * bonus lines of approved or paid bonuses only, never anyone else's.
 */
class OwnPay
{
    public function __construct(private Payrolls $payrolls, private Loans $loans, private Settlements $settlements, private PayrollPresenter $presenter) {}

    /**
     * @param  list<string>  $employeeIds
     * @return list<array<string, mixed>>
     */
    public function loans(Organization $company, array $employeeIds): array
    {
        return $this->payrolls->query(Loan::class, $company)->whereIn('employee_id', $employeeIds)->whereIn('status', [Loan::ACTIVE, Loan::CLOSED])
            ->orderByDesc('paid_out_on')->get()
            ->map(fn (Loan $loan) => $this->presenter->loan($loan, $this->loans->schedule($company, $loan)))->values()->all();
    }

    /**
     * @param  list<string>  $employeeIds
     * @return list<array<string, mixed>>
     */
    public function bonuses(Organization $company, array $employeeIds): array
    {
        $bonuses = $this->released($company);

        return $this->payrolls->query(BonusLine::class, $company)->whereIn('employee_id', $employeeIds)->whereIn('bonus_run_id', $bonuses->keys()->all())
            ->where('gross_minor', '>', 0)->get()
            ->sortByDesc(fn (BonusLine $line) => $bonuses[$line->bonus_run_id]->bonus_on->toDateString())
            ->map(fn (BonusLine $line) => $this->presenter->bonusLine($line, $bonuses[$line->bonus_run_id]))->values()->all();
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, mixed>|null
     */
    public function bonusLine(Organization $company, array $employeeIds, string $lineId): ?array
    {
        $line = $this->payrolls->query(BonusLine::class, $company)->whereKey($lineId)->whereIn('employee_id', $employeeIds)->first();
        $bonus = $line === null ? null : $this->released($company)->get($line->bonus_run_id);

        return $bonus === null ? null : $this->presenter->bonusLine($line, $bonus);
    }

    /**
     * The provident fund: balance (both shares) and movements, newest first.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, mixed>
     */
    public function fund(Organization $company, array $employeeIds): array
    {
        $entries = $this->payrolls->query(PfEntry::class, $company)->whereIn('employee_id', $employeeIds)->orderByDesc('created_at')->limit(240)->get();

        return [
            'employee_minor' => (int) $this->payrolls->query(PfEntry::class, $company)->whereIn('employee_id', $employeeIds)->sum('employee_minor'),
            'employer_minor' => (int) $this->payrolls->query(PfEntry::class, $company)->whereIn('employee_id', $employeeIds)->sum('employer_minor'),
            'currency' => $this->payrolls->currency($company),
            'entries' => $entries->map(fn (PfEntry $entry) => $this->presenter->pfEntry($entry))->values()->all(),
        ];
    }

    /**
     * Final settlements once approved.
     *
     * @param  list<string>  $employeeIds
     * @return list<array<string, mixed>>
     */
    public function settlements(Organization $company, array $employeeIds): array
    {
        return $this->payrolls->query(Settlement::class, $company)->whereIn('employee_id', $employeeIds)->whereIn('status', [Settlement::APPROVED, Settlement::PAID])->get()
            ->map(fn (Settlement $settlement) => $this->presenter->settlement($settlement))->values()->all();
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, mixed>|null
     */
    public function settlement(Organization $company, array $employeeIds, string $id): ?array
    {
        $settlement = $this->payrolls->query(Settlement::class, $company)->whereKey($id)->whereIn('employee_id', $employeeIds)
            ->whereIn('status', [Settlement::APPROVED, Settlement::PAID])->first();

        return $settlement === null ? null : $this->presenter->settlement($settlement, $this->settlements->linesOf($company, $settlement));
    }

    /** @return Collection<string, BonusRun> */
    private function released(Organization $company)
    {
        return $this->payrolls->query(BonusRun::class, $company)->whereIn('status', [BonusRun::APPROVED, BonusRun::PAID])->get()->keyBy('id');
    }
}
