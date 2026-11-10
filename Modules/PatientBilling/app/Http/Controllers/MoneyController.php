<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Http\Controllers\Concerns\FindsPatientBilling;
use Modules\PatientBilling\Http\PatientBillingPresenter;
use Modules\PatientBilling\Http\Requests\MoneyRequest;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Services\PatientBillingStore;
use Modules\PatientBilling\Services\Payments;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Taking money (patient_billing.collect): payments on bills of the unit and
 * advances; giving money back on a bill (patient_billing.approve); finding
 * the patient to bill (through the Patients directory) and their advance.
 */
class MoneyController extends Controller
{
    use FindsPatientBilling;

    public function __construct(private PatientBillingStore $store, private Payments $payments, private PatientBillingPresenter $presenter) {}

    public function store(MoneyRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.collect', $unit);
        $data = $request->validated();
        if ($data['kind'] === 'payment') {
            $this->bill($unit, $company, (string) $data['bill_id']);
        }
        $payment = $this->payments->receive($company, $unit, $data, $request->user());

        return response()->json(['data' => $this->presenter->payment($payment)], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function refund(MoneyRequest $request, string $organization, string $bill): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->bill($unit, $company, $bill);
        Gate::authorize('patient_billing.approve', $unit);
        $refund = $this->payments->refund($company, $unit, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->payment($refund)], $refund->wasRecentlyCreated ? 201 : 200);
    }

    /** Patients to bill: by patient number, mobile or name (throttled). */
    public function patients(Request $request, PatientDirectory $directory, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.collect', $unit);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return response()->json(['data' => array_map(fn ($patient) => [
            ...$this->presenter->patient($patient),
            'advance_balance_minor' => $this->payments->advanceBalance($company, $patient->id),
        ], $directory->search($company, $term, 15))]);
    }

    private function bill($unit, $company, string $id): Bill
    {
        return $this->store->query(Bill::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PatientBillingException::notFound('bill');
    }
}
