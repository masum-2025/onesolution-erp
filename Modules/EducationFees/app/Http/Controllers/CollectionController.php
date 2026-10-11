<?php

namespace Modules\EducationFees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Http\Controllers\Concerns\FindsFeeUnit;
use Modules\EducationFees\Http\Controllers\Concerns\NamesPeople;
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
    use FindsFeeUnit, NamesPeople;

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

        return response()->json([
            'data' => $receipts->map(fn (Receipt $receipt) => $this->presenter->receipt($receipt, student: $students[$receipt->student_id] ?? null))->values(),
            'people' => $this->people($receipts->pluck('collected_by')),
        ]);
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

        $students = app(AcademicDirectory::class)->students($company, $receipts->pluck('student_id')->unique()->values()->all());

        return response()->json([
            'data' => $voids->map(fn (ReceiptVoid $void) => [
                ...$this->presenter->receiptVoid($void, $receipts[$void->receipt_id] ?? null),
                'student' => isset($receipts[$void->receipt_id]) ? array_intersect_key($students[$receipts[$void->receipt_id]->student_id] ?? [], array_flip(['id', 'code', 'name'])) : null,
            ])->values(),
            'people' => $this->people([...$voids->pluck('requested_by'), ...$voids->pluck('decided_by'), ...$receipts->pluck('collected_by')]),
        ]);
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

        return response()->json([
            'data' => $refunds->map(fn (Refund $refund) => $this->presenter->refund($refund, $students[$refund->student_id] ?? null))->values(),
            'people' => $this->people([...$refunds->pluck('requested_by'), ...$refunds->pluck('decided_by')]),
        ]);
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

    /** Students to take fees from, found by name, code or phone, with what they owe and hold. */
    public function findStudents(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $term = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        $found = app(AcademicDirectory::class)->search($company, $term, $this->unitIds($unit), 20);
        $ids = array_column($found, 'id');
        $owed = $this->office->query(Bill::class, $company)->whereIn('student_id', $ids)->where('status', 'open')->get(['student_id', 'total_minor', 'paid_minor'])
            ->groupBy('student_id')->map(fn ($bills) => (int) $bills->sum(fn (Bill $bill) => $bill->balanceMinor()));
        $held = $this->office->query(Advance::class, $company)->whereIn('student_id', $ids)->get(['student_id', 'amount_minor'])
            ->groupBy('student_id')->map(fn ($rows) => (int) $rows->sum('amount_minor'));

        return response()->json(['data' => array_map(fn (array $student) => [
            ...array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local', 'unit_id', 'status'])),
            'owed_minor' => $owed[$student['id']] ?? 0,
            'advance_minor' => $held[$student['id']] ?? 0,
        ], $found)]);
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
            'people' => $this->people($summary['receipts']->pluck('collected_by')),
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
        $bills = $this->office->query(Bill::class, $company)->whereKey($standing->pluck('allocation.bill_id'))->get(['id', 'number', 'billing_key'])->keyBy('id');
        $held = (int) $this->office->query(Advance::class, $company)->where('receipt_id', $receipt->getKey())->sum('amount_minor');

        return [...$this->presenter->receipt(
            $receipt,
            $standing->map(fn (array $row) => ['bill_id' => $row['allocation']->bill_id, 'number' => $bills[$row['allocation']->bill_id]->number ?? null, 'billing_key' => $bills[$row['allocation']->bill_id]->billing_key ?? null, 'amount_minor' => $row['standing']])->values()->all(),
            $held,
            app(AcademicDirectory::class)->student($company, $receipt->student_id),
        ),
            'institution' => $company->displayName(),
            'void_pending' => $this->office->query(ReceiptVoid::class, $company)->where('receipt_id', $receipt->getKey())->where('status', 'pending')->exists(),
            'campus' => $receipt->unit_id === $company->getKey() ? null : Organization::query()->find($receipt->unit_id)?->displayName(),
            'collector' => $this->people([$receipt->collected_by])[$receipt->collected_by ?? ''] ?? null,
        ];
    }
}
