<?php

namespace Modules\Payroll\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Payroll\Models\PaymentDetail;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\Structure;

/**
 * An employee's pay: their basic and structure from a day (a new salary
 * ends the one before it the day before; a later one is replaced), and
 * where it is paid. Salaries and payment details are audited; account
 * numbers are encrypted and never logged.
 */
class Salaries
{
    public function __construct(private Payrolls $payrolls, private AuditLogger $audit) {}

    /** The salary in force on a day, or null. */
    public function on(Organization $company, string $employeeId, CarbonImmutable $day): ?Salary
    {
        $date = $day->toDateString();

        return $this->payrolls->query(Salary::class, $company)->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date)->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->first();
    }

    /**
     * @param  array{structure_id: string, basic_minor: int, effective_from: string, reason?: string|null}  $data
     */
    public function assign(Organization $company, EmployeeRecord $employee, array $data, User $actor): Salary
    {
        $structure = $this->payrolls->query(Structure::class, $company)->whereKey($data['structure_id'])->where('is_active', true)->first();
        if ($structure === null) {
            throw ValidationException::withMessages(['structure_id' => __('payroll::payroll.validation.structure')]);
        }
        $from = CarbonImmutable::parse($data['effective_from'], 'UTC');
        if ($from->lessThan($employee->joinedOn)) {
            throw ValidationException::withMessages(['effective_from' => __('payroll::payroll.validation.before_joining')]);
        }

        return $this->payrolls->transaction($company, function () use ($company, $employee, $data, $structure, $from, $actor) {
            $salaries = fn () => $this->payrolls->query(Salary::class, $company)->where('employee_id', $employee->id);
            $old = $this->on($company, $employee->id, $from);
            $salaries()->where('effective_from', '>=', $from->toDateString())->delete();
            $salaries()->where('effective_from', '<', $from->toDateString())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString()]);

            $salary = new Salary;
            $salary->fill([
                'organization_id' => $company->getKey(), 'employee_id' => $employee->id, 'structure_id' => $structure->getKey(),
                'basic_minor' => $data['basic_minor'], 'effective_from' => $from->toDateString(), 'reason' => $data['reason'] ?? null, 'created_by' => $actor->getKey(),
            ])->save();
            $this->audit->record('payroll.salary_set', $salary, old: $old === null ? [] : ['basic_minor' => $old->basic_minor, 'structure_id' => $old->structure_id],
                new: ['employee_id' => $employee->id, 'basic_minor' => $salary->basic_minor, 'structure_id' => $structure->getKey(), 'effective_from' => $from->toDateString()],
                reason: $data['reason'] ?? null, actor: $actor, organizationId: $company->getKey());

            return $salary;
        });
    }

    /**
     * @param  array{method: string, provider?: string|null, account_name?: string|null, account_number?: string|null, branch?: string|null}  $data
     */
    public function setPaymentDetails(Organization $company, EmployeeRecord $employee, array $data, User $actor): PaymentDetail
    {
        if ($data['method'] !== 'cash' && empty($data['account_number'])) {
            throw ValidationException::withMessages(['account_number' => __('payroll::payroll.validation.account_number')]);
        }

        return $this->payrolls->transaction($company, function () use ($company, $employee, $data, $actor) {
            $detail = $this->payrolls->query(PaymentDetail::class, $company)->where('employee_id', $employee->id)->lockForUpdate()->first()
                ?? new PaymentDetail(['organization_id' => $company->getKey(), 'employee_id' => $employee->id, 'version' => 0]);
            $cash = $data['method'] === 'cash';
            $detail->fill([
                'method' => $data['method'],
                'provider' => $cash ? null : ($data['provider'] ?? null),
                'account_name' => $cash ? null : ($data['account_name'] ?? null),
                'account_number' => $cash ? null : $data['account_number'],
                'branch' => $cash ? null : ($data['branch'] ?? null),
            ]);
            $detail->version = ($detail->version ?? 0) + 1;
            $detail->save();
            // Never the number itself in the audit log.
            $this->audit->record('payroll.payment_details_set', $detail, new: ['employee_id' => $employee->id, 'method' => $detail->method, 'provider' => $detail->provider, 'account' => $detail->maskedNumber()],
                actor: $actor, organizationId: $company->getKey());

            return $detail;
        });
    }
}
