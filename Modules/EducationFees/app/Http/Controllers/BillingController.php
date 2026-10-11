<?php

namespace Modules\EducationFees\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Http\Controllers\Concerns\FindsFeeUnit;
use Modules\EducationFees\Http\FeePresenter;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\FeeRun;
use Modules\EducationFees\Models\Fine;
use Modules\EducationFees\Services\Allocations;
use Modules\EducationFees\Services\Billing;
use Modules\EducationFees\Services\FeeOffice;
use Modules\EducationFees\Services\Fines;

/**
 * Billing runs and bills at the unit in the address and the campuses below
 * it (read with education_fees.view; runs and cancelling with .bill; fine
 * waivers with .approve), and a student's bills.
 */
class BillingController extends Controller
{
    use FindsFeeUnit;

    public function __construct(private FeeOffice $office, private Billing $billing, private FeePresenter $presenter) {}

    public function runs(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate(['session_id' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', 'in:draft,final,cancelled']]);
        $runs = $this->office->query(FeeRun::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['session_id'] ?? null, fn ($query, $id) => $query->where('session_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('issue_date')->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $runs->map(fn (FeeRun $run) => $this->presenter->run($run))->values()]);
    }

    public function showRun(string $organization, string $run): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $found = $this->recordIn(FeeRun::class, $unit, $company, $run, 'run');
        $bills = $this->office->query(Bill::class, $company)->where('run_id', $found->getKey())->get();
        $students = app(AcademicDirectory::class)->students($company, $bills->pluck('student_id')->unique()->values()->all());
        $today = $this->billing->today($company);

        return response()->json(['data' => [
            ...$this->presenter->run($found),
            'bills' => $bills->sortBy(fn (Bill $bill) => $students[$bill->student_id]['code'] ?? '')
                ->map(fn (Bill $bill) => $this->presenter->bill($bill, student: $students[$bill->student_id] ?? null, today: $today))->values(),
        ]]);
    }

    public function storeRun(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.bill', $unit);
        $sessions = array_column(app(AcademicDirectory::class)->sessions($company), 'id');
        $data = $request->validate([
            'kind' => ['required', Rule::in(FeeRun::KINDS)],
            'session_id' => ['required', Rule::in($sessions)],
            'period' => ['required_if:kind,monthly', 'prohibited_unless:kind,monthly', 'nullable', 'date_format:Y-m'],
            'level_id' => ['nullable', 'string', 'size:26'],
            'section_id' => ['nullable', 'string', 'size:26'],
            'head_ids' => ['required_if:kind,other', 'prohibited_unless:kind,other', 'array', 'min:1', 'max:30'],
            'head_ids.*' => ['string', 'size:26', 'distinct'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required_unless:kind,monthly', 'nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'note' => ['nullable', 'string', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
        ]);
        ['run' => $run, 'skipped' => $skipped] = $this->billing->createRun($company, $unit, $data, $request->user());

        return response()->json(['data' => $this->presenter->run($run), 'meta' => ['skipped' => $skipped]], 201);
    }

    public function refreshRun(Request $request, string $organization, string $run): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.bill', $unit);
        $found = $this->recordIn(FeeRun::class, $unit, $company, $run, 'run');
        ['run' => $saved, 'skipped' => $skipped] = $this->billing->refreshRun($company, $found, $this->version($request), $request->user());

        return response()->json(['data' => $this->presenter->run($saved), 'meta' => ['skipped' => $skipped]]);
    }

    public function finalizeRun(Request $request, string $organization, string $run): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.bill', $unit);
        $found = $this->recordIn(FeeRun::class, $unit, $company, $run, 'run');

        return response()->json(['data' => $this->presenter->run($this->billing->finalizeRun($company, $found, $this->version($request), $request->user()))]);
    }

    public function cancelRun(Request $request, string $organization, string $run): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.bill', $unit);
        $found = $this->recordIn(FeeRun::class, $unit, $company, $run, 'run');

        return response()->json(['data' => $this->presenter->run($this->billing->cancelRun($company, $found, $this->version($request), $request->user()))]);
    }

    public function bills(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate([
            'student_id' => ['nullable', 'string', 'size:26'], 'run_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::in(Bill::STATUSES)], 'overdue' => ['nullable', 'boolean'],
        ]);
        $today = $this->billing->today($company);
        $bills = $this->office->query(Bill::class, $company)->whereIn('unit_id', $this->unitIds($unit))->where('status', '!=', 'draft')
            ->when($filters['student_id'] ?? null, fn ($query, $id) => $query->where('student_id', $id))
            ->when($filters['run_id'] ?? null, fn ($query, $id) => $query->where('run_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['overdue'] ?? false, fn ($query) => $query->where('status', 'open')->where('due_date', '<', $today)->whereColumn('paid_minor', '<', 'total_minor'))
            ->orderByDesc('issue_date')->orderByDesc('number')->limit(500)->get();
        $students = app(AcademicDirectory::class)->students($company, $bills->pluck('student_id')->unique()->values()->all());

        return response()->json(['data' => $bills->map(fn (Bill $bill) => $this->presenter->bill($bill, student: $students[$bill->student_id] ?? null, today: $today))->values()]);
    }

    public function showBill(string $organization, string $bill): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);

        return response()->json(['data' => $this->billView($company, $this->recordIn(Bill::class, $unit, $company, $bill, 'bill'))]);
    }

    public function cancelBill(Request $request, string $organization, string $bill): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.bill', $unit);
        $found = $this->recordIn(Bill::class, $unit, $company, $bill, 'bill');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->billing->cancelBill($company, $found, $data['reason'], (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->billView($company, $saved)]);
    }

    public function waiveFine(Request $request, string $organization, string $bill): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.approve', $unit);
        $found = $this->recordIn(Bill::class, $unit, $company, $bill, 'bill');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = app(Fines::class)->waive($company, $found, $data['reason'], (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->billView($company, $saved)]);
    }

    /** A student's bills with what they still owe in all. */
    public function student(string $organization, string $student): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $record = app(AcademicDirectory::class)->student($company, $student);
        if ($record === null || ! in_array($record['unit_id'], $this->unitIds($unit), true)) {
            throw FeeException::notFound('student');
        }
        $today = $this->billing->today($company);
        $bills = $this->office->query(Bill::class, $company)->where('student_id', $student)->where('status', '!=', 'draft')->orderByDesc('issue_date')->orderByDesc('number')->get();

        return response()->json(['data' => [
            'student' => array_intersect_key($record, array_flip(['id', 'code', 'name', 'name_local', 'unit_id', 'status'])),
            'owed_minor' => (int) $bills->whereIn('status', ['open'])->sum(fn (Bill $bill) => $bill->balanceMinor()),
            'advance_minor' => app(Allocations::class)->advanceBalance($company, $student),
            'overdue_minor' => (int) $bills->filter(fn (Bill $bill) => $bill->status === 'open' && $bill->due_date->toDateString() < $today)->sum(fn (Bill $bill) => $bill->balanceMinor()),
            'bills' => $bills->map(fn (Bill $bill) => $this->presenter->bill($bill, today: $today))->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function billView($company, Bill $bill): array
    {
        $student = app(AcademicDirectory::class)->student($company, $bill->student_id);
        $fines = $this->office->query(Fine::class, $company)->where('bill_id', $bill->getKey())->orderBy('applied_on')->orderBy('created_at')->get();
        $lines = $this->billing->lines($company, $bill);
        $heads = $this->office->query(FeeHead::class, $company)->whereKey($lines->pluck('head_id')->unique())->get()->keyBy('id');

        return [
            ...$this->presenter->bill($bill, $lines, $fines, $student, $this->billing->today($company)),
            'heads' => $heads->map(fn (FeeHead $head) => ['id' => $head->getKey(), 'code' => $head->code, 'name' => $head->texts('name')])->values(),
        ];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['base_version' => ['required', 'integer']])['base_version'];
    }
}
