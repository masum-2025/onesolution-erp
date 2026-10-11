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
use Modules\EducationFees\Models\Advance;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Models\ReceiptVoid;
use Modules\EducationFees\Models\Refund;
use Modules\EducationFees\Services\Allocations;
use Modules\EducationFees\Services\Collections;
use Modules\EducationFees\Services\FeeOffice;

/**
 * The fee counter at the unit and below: taking fees and receipts
 * (education_fees.collect), asking to void a receipt or pay an advance back
 * (.collect), deciding them (.void, never one's own), and the day's takings
 * (.view).
 */
class CollectionController extends Controller
{
    use FindsFeeUnit;

    public function __construct(private FeeOffice $office, private Collections $collections, private Allocations $allocations, private FeePresenter $presenter) {}

    public function receipts(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate([
            'student_id' => ['nullable', 'string', 'size:26'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:valid,voided'], 'collected_by' => ['nullable', 'string', 'size:26'],
        ]);
        $receipts = $this->office->query(Receipt::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['student_id'] ?? null, fn ($query, $id) => $query->where('student_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $day) => $query->where('received_on', '>=', $day))
            ->when($filters['to'] ?? null, fn ($query, $day) => $query->where('received_on', '<=', $day))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['collected_by'] ?? null, fn ($query, $id) => $query->where('collected_by', $id))
            ->orderByDesc('received_on')->orderByDesc('number')->limit(500)->get();
        $students = app(AcademicDirectory::class)->students($company, $receipts->pluck('student_id')->unique()->values()->all());

        return response()->json(['data' => $receipts->map(fn (Receipt $receipt) => $this->presenter->receipt($receipt, student: $students[$receipt->student_id] ?? null))->values()]);
    }

    public function showReceipt(string $organization, string $receipt): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);

        return response()->json(['data' => $this->receiptView($company, $this->recordIn(Receipt::class, $unit, $company, $receipt, 'receipt'))]);
    }

    public function collect(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.collect', $unit);
        $data = $request->validate([
            'student_id' => ['required', 'string', 'size:26'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(['cash', 'bank', 'mobile'])],
            'reference' => ['required_if:method,bank,mobile', 'nullable', 'string', 'max:100'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->office->today($company)->toDateString()],
            'note' => ['nullable', 'string', 'max:300'],
            'op_id' => ['nullable', 'string', 'max:64'],
            'allocations' => ['nullable', 'array', 'min:1', 'max:50'],
            'allocations.*' => ['array:bill_id,amount_minor'],
            'allocations.*.bill_id' => ['required', 'string', 'size:26', 'distinct'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);
        $student = $this->studentHere($company, $unit, $data['student_id']);
        $receipt = $this->collections->collect($company, [...$data, 'unit_id' => $student['unit_id']], $request->user());

        return response()->json(['data' => $this->receiptView($company, $receipt)], 201);
    }

    public function requestVoid(Request $request, string $organization, string $receipt): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.collect', $unit);
        $found = $this->recordIn(Receipt::class, $unit, $company, $receipt, 'receipt');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        $void = $this->collections->requestVoid($company, $found, $reason, $request->user());

        return response()->json(['data' => $this->presenter->receiptVoid($void, $found->refresh())], 201);
    }

    public function voids(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']])['status'] ?? null;
        $voids = $this->office->query(ReceiptVoid::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($status, fn ($query) => $query->where('status', $status))->orderByDesc('created_at')->limit(200)->get();
        $receipts = $this->office->query(Receipt::class, $company)->whereKey($voids->pluck('receipt_id'))->get()->keyBy('id');

        return response()->json(['data' => $voids->map(fn (ReceiptVoid $void) => $this->presenter->receiptVoid($void, $receipts[$void->receipt_id] ?? null))->values()]);
    }

    public function approveVoid(Request $request, string $organization, string $void): JsonResponse
    {
        return $this->decideVoid($request, $organization, $void, true);
    }

    public function rejectVoid(Request $request, string $organization, string $void): JsonResponse
    {
        return $this->decideVoid($request, $organization, $void, false);
    }

    public function refunds(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'student_id' => ['nullable', 'string', 'size:26']]);
        $refunds = $this->office->query(Refund::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['student_id'] ?? null, fn ($query, $id) => $query->where('student_id', $id))
            ->orderByDesc('created_at')->limit(200)->get();
        $students = app(AcademicDirectory::class)->students($company, $refunds->pluck('student_id')->unique()->values()->all());

        return response()->json(['data' => $refunds->map(fn (Refund $refund) => $this->presenter->refund($refund, $students[$refund->student_id] ?? null))->values()]);
    }

    public function requestRefund(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.collect', $unit);
        $data = $request->validate([
            'student_id' => ['required', 'string', 'size:26'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(['cash', 'bank', 'mobile'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $student = $this->studentHere($company, $unit, $data['student_id']);
        $refund = $this->collections->requestRefund($company, [...$data, 'unit_id' => $student['unit_id']], $request->user());

        return response()->json(['data' => $this->presenter->refund($refund, $student)], 201);
    }

    public function approveRefund(Request $request, string $organization, string $refund): JsonResponse
    {
        return $this->decideRefund($request, $organization, $refund, true);
    }

    public function rejectRefund(Request $request, string $organization, string $refund): JsonResponse
    {
        return $this->decideRefund($request, $organization, $refund, false);
    }

    /** The takings of a day (or days): by method and by person, for closing the counter. */
    public function summary(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $today = $this->office->today($company)->toDateString();
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'collected_by' => ['nullable', 'string', 'size:26']]);
        $summary = $this->collections->summary($company, $this->unitIds($unit), $data['from'] ?? $today, $data['to'] ?? $data['from'] ?? $today, $data['collected_by'] ?? null);
        $students = app(AcademicDirectory::class)->students($company, $summary['receipts']->pluck('student_id')->unique()->values()->all());

        return response()->json(['data' => [
            ...collect($summary)->except('receipts')->all(),
            'from' => $data['from'] ?? $today,
            'to' => $data['to'] ?? $data['from'] ?? $today,
            'receipts' => $summary['receipts']->map(fn (Receipt $receipt) => $this->presenter->receipt($receipt, student: $students[$receipt->student_id] ?? null))->values(),
        ]]);
    }

    private function decideVoid(Request $request, string $organization, string $void, bool $approve): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.void', $unit);
        $found = $this->recordIn(ReceiptVoid::class, $unit, $company, $void, 'void');
        $data = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->collections->decideVoid($company, $found, $approve, $data['note'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->presenter->receiptVoid($saved, $this->office->query(Receipt::class, $company)->find($saved->receipt_id))]);
    }

    private function decideRefund(Request $request, string $organization, string $refund, bool $approve): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.void', $unit);
        $found = $this->recordIn(Refund::class, $unit, $company, $refund, 'refund');
        $data = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->collections->decideRefund($company, $found, $approve, $data['note'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->presenter->refund($saved, app(AcademicDirectory::class)->student($company, $saved->student_id))]);
    }

    /** @return array<string, mixed> */
    private function studentHere($company, $unit, string $studentId): array
    {
        $student = app(AcademicDirectory::class)->student($company, $studentId);
        if ($student === null || ! in_array($student['unit_id'], $this->unitIds($unit), true)) {
            throw FeeException::notFound('student');
        }

        return $student;
    }

    /** @return array<string, mixed> */
    private function receiptView($company, Receipt $receipt): array
    {
        $standing = $this->allocations->standing($company, receiptId: $receipt->getKey());
        $numbers = $this->office->query(Bill::class, $company)->whereKey($standing->pluck('allocation.bill_id'))->pluck('number', 'id');
        $held = (int) $this->office->query(Advance::class, $company)->where('receipt_id', $receipt->getKey())->sum('amount_minor');

        return $this->presenter->receipt(
            $receipt,
            $standing->map(fn (array $row) => ['bill_id' => $row['allocation']->bill_id, 'number' => $numbers[$row['allocation']->bill_id] ?? null, 'amount_minor' => $row['standing']])->values()->all(),
            $held,
            app(AcademicDirectory::class)->student($company, $receipt->student_id),
        );
    }
}
