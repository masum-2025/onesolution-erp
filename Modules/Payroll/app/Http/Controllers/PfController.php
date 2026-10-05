<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Models\PfEntry;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Services\Payrolls;

/** The provident fund of a company's employees: each one's balance (payroll.view at the company). */
class PfController extends Controller
{
    use FindsPayroll;

    public function __construct(private Payrolls $payrolls) {}

    public function index(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);
        $entries = $this->payrolls->query(PfEntry::class, $company)->get(['employee_id', 'kind', 'period', 'employee_minor', 'employer_minor']);
        $names = $this->payrolls->query(Slip::class, $company)->whereIn('employee_id', $entries->pluck('employee_id')->unique()->all())
            ->orderByDesc('created_at')->get(['employee_id', 'employee_code', 'employee_name'])->unique('employee_id')->keyBy('employee_id');

        $rows = $entries->groupBy('employee_id')->map(fn ($items, $employeeId) => [
            'employee_id' => $employeeId,
            'employee_code' => $names[$employeeId]->employee_code ?? null,
            'employee_name' => $names[$employeeId]->employee_name ?? null,
            'employee_minor' => (int) $items->sum('employee_minor'),
            'employer_minor' => (int) $items->sum('employer_minor'),
            'last_period' => $items->where('kind', PfEntry::CONTRIBUTION)->max('period'),
        ])->sortBy('employee_name')->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['currency' => $this->payrolls->currency($company), 'total_minor' => (int) $rows->sum(fn ($row) => $row['employee_minor'] + $row['employer_minor'])],
        ]);
    }
}
