<?php

namespace Modules\EducationFees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Payments\Services\CollectPayment;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\Advance;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\BillLine;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\Fine;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Payments\FeeBillCollectable;
use Modules\EducationFees\Services\Allocations;
use Modules\EducationFees\Services\FeeOffice;

/**
 * A family's fees in the client's portal (B2B2C): the bills, receipts and
 * advance of a student the member is linked to (the student, a parent; any
 * relation), chosen by the portal record (?record=). Paying a bill online
 * starts a payment for its whole balance; the amount never comes from the
 * request. Staff details (who took the money, how a discount was worked
 * out) stay out. Another student's bill or receipt is the same 404.
 */
class PortalFeesController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private FeeOffice $office,
        private Allocations $allocations,
        private FeeBillCollectable $collectable,
    ) {}

    /** The student's bills (unpaid first), what they owe, hold and paid. */
    public function show(Request $request): JsonResponse
    {
        [$company, $student] = $this->me($request);
        $today = $this->office->today($company)->toDateString();
        $bills = $this->office->query(Bill::class, $company)->where('student_id', $student['id'])->whereIn('status', ['open', 'paid'])
            ->orderByDesc('issue_date')->orderByDesc('number')->limit(60)->get();
        $open = $bills->where('status', 'open')->filter(fn (Bill $bill) => $bill->balanceMinor() > 0);
        $receipts = $this->office->query(Receipt::class, $company)->where('student_id', $student['id'])->orderByDesc('received_on')->orderByDesc('number')->limit(30)->get();

        return response()->json(['data' => [
            'student' => array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local'])),
            'institution' => $company->displayName(),
            'currency' => rescue(fn () => $this->office->currency($company), null, false),
            'owed_minor' => (int) $open->sum(fn (Bill $bill) => $bill->balanceMinor()),
            'overdue_minor' => (int) $open->filter(fn (Bill $bill) => $bill->due_date->toDateString() < $today)->sum(fn (Bill $bill) => $bill->balanceMinor()),
            'advance_minor' => $this->allocations->advanceBalance($company, $student['id']),
            'bills' => $bills->sortBy(fn (Bill $bill) => $bill->status === 'open' ? 0 : 1)->values()->map(fn (Bill $bill) => [
                ...$this->bill($bill, $today),
                'can_pay' => $bill->status === 'open' && $this->collectable->due($this->context->organization(), $bill->getKey(), $request->user()) !== null,
            ]),
            'receipts' => $receipts->map(fn (Receipt $receipt) => [
                'id' => $receipt->getKey(), 'number' => $receipt->number, 'received_on' => $receipt->received_on->toDateString(),
                'method' => $receipt->method, 'amount_minor' => $receipt->amount_minor, 'status' => $receipt->status,
            ])->values(),
        ]]);
    }

    /** One bill: what each head costs, its discount, late fines, what paid it. */
    public function showBill(Request $request, string $bill): JsonResponse
    {
        [$company, $student] = $this->me($request);
        $found = $this->ownBill($company, $student, $bill);
        $lines = $this->office->query(BillLine::class, $company)->where('bill_id', $found->getKey())->orderBy('created_at')->orderBy('id')->get();
        $heads = $this->office->query(FeeHead::class, $company)->whereKey($lines->pluck('head_id'))->get()->keyBy('id');
        $fines = $this->office->query(Fine::class, $company)->where('bill_id', $found->getKey())->orderBy('applied_on')->get();
        $paid = $this->allocations->standing($company, billId: $found->getKey());
        $numbers = $this->office->query(Receipt::class, $company)->whereKey($paid->pluck('allocation.receipt_id')->filter())->pluck('number', 'id');

        return response()->json(['data' => [
            ...$this->bill($found, $this->office->today($company)->toDateString()),
            'can_pay' => $found->status === 'open' && $this->collectable->due($this->context->organization(), $found->getKey(), $request->user()) !== null,
            'lines' => $lines->map(fn (BillLine $line) => [
                'head' => ['code' => $heads[$line->head_id]->code ?? '', 'name' => isset($heads[$line->head_id]) ? $heads[$line->head_id]->texts('name') : []],
                ...$line->only(['amount_minor', 'discount_minor', 'tax_minor', 'due_minor']),
            ])->values(),
            'fines' => $fines->map(fn (Fine $fine) => ['kind' => $fine->kind, 'amount_minor' => $fine->amount_minor, 'applied_on' => $fine->applied_on->toDateString()])->values(),
            'payments' => $paid->map(fn (array $row) => [
                'amount_minor' => $row['standing'], 'number' => $numbers[$row['allocation']->receipt_id ?? ''] ?? null, 'receipt_id' => $row['allocation']->receipt_id,
                'on' => $row['allocation']->created_at?->toDateString(),
            ])->values(),
        ]]);
    }

    /** A receipt to keep or print. */
    public function showReceipt(Request $request, string $receipt): JsonResponse
    {
        [$company, $student] = $this->me($request);
        $found = $this->office->query(Receipt::class, $company)->whereKey($receipt)->where('student_id', $student['id'])->first()
            ?? throw FeeException::notFound('receipt');
        $paid = $this->allocations->standing($company, receiptId: $found->getKey());
        $bills = $this->office->query(Bill::class, $company)->whereKey($paid->pluck('allocation.bill_id'))->get(['id', 'number', 'billing_key'])->keyBy('id');
        $held = (int) $this->office->query(Advance::class, $company)->where('receipt_id', $found->getKey())->sum('amount_minor');

        return response()->json(['data' => [
            'id' => $found->getKey(),
            ...$found->only(['number', 'method', 'reference', 'amount_minor', 'currency', 'status']),
            'received_on' => $found->received_on->toDateString(),
            'student' => array_intersect_key($student, array_flip(['id', 'code', 'name'])),
            'institution' => $company->displayName(),
            'campus' => $found->unit_id === $company->getKey() ? null : Organization::query()->find($found->unit_id)?->displayName(),
            'bills' => $paid->map(fn (array $row) => [
                'bill_id' => $row['allocation']->bill_id, 'number' => $bills[$row['allocation']->bill_id]->number ?? null,
                'billing_key' => $bills[$row['allocation']->bill_id]->billing_key ?? null, 'amount_minor' => $row['standing'],
            ])->values(),
            'advance_minor' => $held,
            // Who at the counter took it stays with the institution.
            'collector' => null,
        ]]);
    }

    /** Pay a bill's whole balance online (the amount comes from the bill, never from here). */
    public function pay(Request $request, string $bill, CollectPayment $collect): JsonResponse
    {
        $request->validate(['op_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/']]);
        [$company, $student] = $this->me($request);
        $found = $this->ownBill($company, $student, $bill);
        $payment = $collect->start($this->context->organization(), $request->user(), FeeBillCollectable::KEY, $found->getKey(), $request->string('op_id')->toString());

        return response()->json(['data' => ['payment_id' => $payment->getKey(), 'checkout_url' => $payment->checkout_url, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency_code]], 201);
    }

    /** @return array<string, mixed> */
    private function bill(Bill $bill, string $today): array
    {
        return [
            'id' => $bill->getKey(),
            ...$bill->only(['number', 'billing_key', 'status', 'currency', 'gross_minor', 'discount_minor', 'tax_minor', 'fine_minor', 'total_minor', 'paid_minor']),
            'balance_minor' => $bill->balanceMinor(),
            'issue_date' => $bill->issue_date->toDateString(),
            'due_date' => $bill->due_date->toDateString(),
            'overdue' => $bill->status === 'open' && $bill->due_date->toDateString() < $today && $bill->balanceMinor() > 0,
        ];
    }

    /** @param  array<string, mixed>  $student */
    private function ownBill(Organization $company, array $student, string $id): Bill
    {
        return $this->office->query(Bill::class, $company)->whereKey($id)->where('student_id', $student['id'])->whereIn('status', ['open', 'paid'])->first()
            ?? throw FeeException::notFound('bill');
    }

    /**
     * The institution and the student of the member's portal record (any
     * relation to an education.student, active).
     *
     * @return array{0: Organization, 1: array<string, mixed>}
     */
    private function me(Request $request): array
    {
        $record = $request->validate(['record' => ['required', 'string', 'size:26']])['record'];
        if (! $this->portal->isPortal()) {
            throw FeeException::notFound('student');
        }
        $membership = $this->context->membership();
        $link = PortalLink::query()->whereKey($record)->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('status', PortalLink::ACTIVE)->first();
        $company = $this->office->companyOf($this->context->organization());
        $student = $link === null ? null : app(AcademicDirectory::class)->student($company, $link->subject_id);

        return [$company, $student ?? throw FeeException::notFound('student')];
    }
}
