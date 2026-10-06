<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Http\Controllers\Concerns\FindsPos;
use Modules\Pos\Services\PosReports;
use Modules\Pos\Services\Tills;

/**
 * Takings between two days at the counters of the unit in the address and
 * below, or one counter: for supervisors and managers (pos.supervise or
 * pos.manage), since it shows each cashier's takings. Rate limited.
 */
class ReportController extends Controller
{
    use FindsPos;

    public function __construct(private Tills $tills, private PosReports $reports) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.supervise', 'pos.manage'], $unit), 403);
        $today = CarbonImmutable::now($this->tills->timezone($company))->toDateString();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'register_id' => ['nullable', 'string', 'max:26'],
        ]);
        $from = $filters['from'] ?? $today;
        $to = $filters['to'] ?? $today;
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= PosReports::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('pos::pos.validation.report_range', ['days' => PosReports::MAX_DAYS])]);
        }
        $registers = empty($filters['register_id']) ? $this->registerIds($unit, $company) : [(string) $this->registerIn($unit, $company, $filters['register_id'])->getKey()];

        return response()->json(['data' => $this->reports->takings($company, $registers, $from, $to)]);
    }
}
