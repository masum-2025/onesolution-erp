<?php

namespace Modules\Payroll\Services;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Models\BonusLine;
use Modules\Payroll\Models\BonusRun;
use Modules\Payroll\Models\Loan;

/**
 * What employees see of their own loans and bonuses, in the app (through
 * their linked login) and in the client's portal: loans once approved,
 * bonus lines of approved or paid bonuses only, never anyone else's.
 */
class OwnPay
{
    public function __construct(private Payrolls $payrolls, private Loans $loans, private PayrollPresenter $presenter) {}

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

    /** @return Collection<string, BonusRun> */
    private function released(Organization $company)
    {
        return $this->payrolls->query(BonusRun::class, $company)->whereIn('status', [BonusRun::APPROVED, BonusRun::PAID])->get()->keyBy('id');
    }
}
