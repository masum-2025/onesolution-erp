<?php

namespace Modules\Payroll\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Payroll\Models\PaymentDetail;

/**
 * What to send the bank: each person paid, where (account in full), and
 * how much. Built for the screen to write as a file; never stored.
 */
class BankFile
{
    public function __construct(private Payrolls $payrolls) {}

    /**
     * @param  iterable<array{employee_id: string, employee_code: string, employee_name: string, amount_minor: int}>  $payees
     * @return list<array{employee_code: string, employee_name: string, method: string|null, provider: string|null, account_name: string|null, account_number: string|null, branch: string|null, amount_minor: int}>
     */
    public function rows(Organization $company, iterable $payees): array
    {
        $payees = collect($payees)->filter(fn (array $payee) => $payee['amount_minor'] > 0)->values();
        $details = $this->payrolls->query(PaymentDetail::class, $company)->whereIn('employee_id', $payees->pluck('employee_id')->all())->get()->keyBy('employee_id');

        return $payees->map(function (array $payee) use ($details) {
            $detail = $details[$payee['employee_id']] ?? null;

            return [
                'employee_code' => $payee['employee_code'], 'employee_name' => $payee['employee_name'],
                'method' => $detail?->method, 'provider' => $detail?->provider, 'account_name' => $detail?->account_name,
                'account_number' => $detail?->account_number, 'branch' => $detail?->branch, 'amount_minor' => $payee['amount_minor'],
            ];
        })->all();
    }
}
