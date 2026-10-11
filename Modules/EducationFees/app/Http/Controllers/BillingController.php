<?php

namespace Modules\EducationFees\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
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
use Modules\EducationFees\Models\Posting;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Services\Allocations;
use Modules\EducationFees\Services\Billing;
use Modules\EducationFees\Services\FeeOffice;
use Modules\EducationFees\Services\FeePostings;
use Modules\EducationFees\Services\FeeReminders;
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

    /**
     * Who owes, in a session at the unit and below (a class or a section):
     * each student with what they owe, what is overdue and since when, most
     * overdue first. Only students who owe something.
     */
    public function dues(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate([
            'session_id' => ['required', 'string', 'size:26'], 'level_id' => ['nullable', 'string', 'size:26'], 'section_id' => ['nullable', 'string', 'size:26'],
            'overdue_only' => ['nullable', 'boolean'],
        ]);
        $rows = app(AcademicDirectory::class)->enrolledStudents($company, $filters['session_id'], $this->unitIds($unit), $filters['level_id'] ?? null, $filters['section_id'] ?? null);
        $today = $this->billing->today($company);
        $bills = $this->office->query(Bill::class, $company)->whereIn('student_id', array_map(fn (array $row) => $row['student']['id'], $rows))->where('status', 'open')
            ->get(['student_id', 'due_date', 'total_minor', 'paid_minor'])->groupBy('student_id');
        $dues = [];
        foreach ($rows as $row) {
            $open = collect($bills[$row['student']['id']] ?? [])->filter(fn (Bill $bill) => $bill->balanceMinor() > 0);
            $late = $open->filter(fn (Bill $bill) => $bill->due_date->toDateString() < $today);
            if ($open->isEmpty() || (($filters['overdue_only'] ?? false) && $late->isEmpty())) {
                continue;
            }
            $oldest = $late->min(fn (Bill $bill) => $bill->due_date->toDateString());
            $dues[] = [
                'student' => array_intersect_key($row['student'], array_flip(['id', 'code', 'name', 'name_local'])),
                'level_id' => $row['enrollment']['level_id'], 'section_id' => $row['enrollment']['section_id'],
                'bills' => $open->count(),
                'owed_minor' => (int) $open->sum(fn (Bill $bill) => $bill->balanceMinor()),
                'overdue_minor' => (int) $late->sum(fn (Bill $bill) => $bill->balanceMinor()),
                'oldest_due' => $oldest,
                'days_overdue' => $oldest === null ? 0 : (int) CarbonImmutable::parse($oldest)->diffInDays(CarbonImmutable::parse($today)),
            ];
        }
        usort($dues, fn (array $a, array $b) => $b['days_overdue'] <=> $a['days_overdue'] ?: $b['owed_minor'] <=> $a['owed_minor']);

        return response()->json(['data' => $dues, 'meta' => [
            'students' => count($dues), 'owed_minor' => array_sum(array_column($dues, 'owed_minor')), 'overdue_minor' => array_sum(array_column($dues, 'overdue_minor')),
        ]]);
    }

    /** The office reminds students (or everyone listed) of what they owe, once a day each. */
    public function remind(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.bill', $unit);
        $ids = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:500'], 'student_ids.*' => ['string', 'size:26', 'distinct']])['student_ids'];
        $here = array_keys(array_filter(app(AcademicDirectory::class)->students($company, $ids), fn (array $student) => in_array($student['unit_id'], $this->unitIds($unit), true)));

        return response()->json(['data' => app(FeeReminders::class)->remindStudents($company, array_values($here), $request->user())]);
    }

    /** Fee events and whether they reached the books (failed ones with their reason first). */
    public function postings(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $company);
        $status = $request->validate(['status' => ['nullable', 'in:pending,posted,failed']])['status'] ?? null;
        $rows = $this->office->query(Posting::class, $company)->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')->limit(300)->get()
            // Failed first, then waiting, then posted; newest first in each.
            ->sortBy(fn (Posting $row) => ['failed' => 0, 'pending' => 1][$row->status] ?? 2)->values();
        $counts = $this->office->query(Posting::class, $company)->get(['status'])->countBy('status');

        return response()->json(['data' => $rows->map(fn (Posting $row) => [
            ...$row->only(['id', 'kind', 'source_id', 'status', 'journal_id', 'attempts', 'error']),
            'posted_at' => $row->posted_at?->toIso8601String(), 'created_at' => $row->created_at?->toIso8601String(),
        ])->values(), 'meta' => ['counts' => ['pending' => $counts['pending'] ?? 0, 'posted' => $counts['posted'] ?? 0, 'failed' => $counts['failed'] ?? 0]]]);
    }

    /** Try again what the books refused (after the period was opened or the account mapped). */
    public function retryPostings(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.configure', $company);

        return response()->json(['data' => ['posted' => app(FeePostings::class)->run($company, retryFailed: true)]]);
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
        $standing = app(Allocations::class)->standing($company, billId: $bill->getKey());
        $receipts = $this->office->query(Receipt::class, $company)->whereKey($standing->pluck('allocation.receipt_id')->filter())->get()->keyBy('id');

        return [
            ...$this->presenter->bill($bill, $lines, $fines, $student, $this->billing->today($company)),
            'heads' => $heads->map(fn (FeeHead $head) => ['id' => $head->getKey(), 'code' => $head->code, 'name' => $head->texts('name')])->values(),
            // What met it: receipts (number, day, how) and the advance.
            'payments' => $standing->map(fn (array $row) => [
                'kind' => $row['allocation']->kind, 'amount_minor' => $row['standing'], 'receipt_id' => $row['allocation']->receipt_id,
                'number' => $receipts[$row['allocation']->receipt_id ?? '']->number ?? null, 'method' => $receipts[$row['allocation']->receipt_id ?? '']->method ?? null,
                'day' => ($receipts[$row['allocation']->receipt_id ?? '']->received_on ?? $row['allocation']->created_at)?->toDateString(),
            ])->values(),
        ];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['base_version' => ['required', 'integer']])['base_version'];
    }
}
