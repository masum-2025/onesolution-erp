<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Http\Controllers\Concerns\FindsPatientBilling;
use Modules\PatientBilling\Http\PatientBillingPresenter;
use Modules\PatientBilling\Http\Requests\DecisionRequest;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\DiscountRequest;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Services\Bills;
use Modules\PatientBilling\Services\PatientBillingStore;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Discounts waiting for approval and the latest refunds, for approvers
 * (patient_billing.approve) at the unit and below; deciding someone else's
 * discount request.
 */
class ApprovalController extends Controller
{
    use FindsPatientBilling;

    public function __construct(private PatientBillingStore $store, private PatientBillingPresenter $presenter, private PatientDirectory $patients) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.approve', $unit);
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']])['status'] ?? DiscountRequest::PENDING;
        $billIds = $this->store->query(Bill::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->select('id');
        $requests = $this->store->query(DiscountRequest::class, $company)->whereIn('bill_id', $billIds)->where('status', $status)
            ->orderByDesc('created_at')->limit(100)->get();
        $refunds = $this->store->query(Payment::class, $company)->whereIn('bill_id', $billIds)->where('kind', Payment::REFUND)
            ->orderByDesc('received_at')->limit(30)->get();
        $bills = $this->store->query(Bill::class, $company)->whereKey([...$requests->pluck('bill_id')->all(), ...$refunds->pluck('bill_id')->all()])->get()->keyBy('id');
        $names = $this->presenter->userNames([...$requests->pluck('requested_by')->all(), ...$requests->pluck('decided_by')->all(), ...$refunds->pluck('received_by')->all()]);

        return response()->json(['data' => [
            'discounts' => $requests->map(fn (DiscountRequest $item) => $this->presenter->discountRequest($item, $names, $bills[$item->bill_id] ?? null,
                isset($bills[$item->bill_id]) ? $this->presenter->patient($this->patients->find($company, $bills[$item->bill_id]->patient_id)) : null))->values(),
            'refunds' => $refunds->map(fn (Payment $refund) => [...$this->presenter->payment($refund, $names), 'bill_number' => $bills[$refund->bill_id]->number ?? null])->values(),
        ]]);
    }

    public function decide(DecisionRequest $request, Bills $bills, string $organization, string $discount): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.approve', $unit);
        $billIds = $this->store->query(Bill::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->select('id');
        $found = $this->store->query(DiscountRequest::class, $company)->whereKey($discount)->whereIn('bill_id', $billIds)->first()
            ?? throw PatientBillingException::notFound('discount');
        $decided = $bills->decideDiscount($company, $found, (bool) $request->validated('approve'), $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->discountRequest($decided, $this->presenter->userNames([$decided->requested_by, $decided->decided_by]))]);
    }
}
