<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Modules\PatientBilling\Http\PatientBillingPresenter;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\BillLine;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Services\PatientBillingStore;
use Modules\Patients\Portal\PatientSubjects;

/**
 * A portal member's own bills (B2B2C): only the final bills of the patients
 * the company linked to them (themselves, or their children), with lines and
 * money in and out. Never open or void bills, other patients or who took the
 * money.
 */
class PortalBillController extends Controller
{
    public function __construct(private PatientBillingStore $store, private PortalAccess $portal, private CurrentContext $context, private PatientBillingPresenter $presenter) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->context->organization();
        $open = $this->portal->isPortal() && in_array($company->type, [OrganizationType::Company, OrganizationType::Personal], true);
        $ids = $open ? $this->portal->subjectIds(PatientSubjects::KEY) : [];
        $bills = $ids === [] ? collect() : $this->store->query(Bill::class, $company)->whereIn('patient_id', $ids)->where('status', Bill::FINAL)
            ->orderByDesc('finalized_at')->limit(100)->get();
        $lines = $bills->isEmpty() ? collect() : $this->store->query(BillLine::class, $company)->whereIn('bill_id', $bills->modelKeys())->whereNull('voided_at')->get()->groupBy('bill_id');
        $money = $bills->isEmpty() ? collect() : $this->store->query(Payment::class, $company)->whereIn('bill_id', $bills->modelKeys())->orderBy('received_at')->get()->groupBy('bill_id');
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'organization' => $company->displayName(),
            'bills' => $bills->map(fn (Bill $bill) => [
                ...array_intersect_key($this->presenter->bill($bill, null), array_flip(['id', 'number', 'status', 'subtotal_minor', 'panel_discount_minor', 'discount_minor', 'tax_minor', 'total_minor', 'paid_minor', 'refunded_minor', 'due_minor', 'panel_due_minor', 'currency', 'finalized_at'])),
                'patient_id' => $bill->patient_id,
                'lines' => ($lines[$bill->getKey()] ?? collect())->map(fn (BillLine $line) => array_intersect_key($this->presenter->line($line, $locale), array_flip(['id', 'description', 'quantity_milli', 'total_minor'])))->values(),
                'payments' => ($money[$bill->getKey()] ?? collect())->map(fn (Payment $payment) => array_intersect_key($this->presenter->payment($payment), array_flip(['id', 'number', 'kind', 'method', 'amount_minor', 'received_at'])))->values(),
            ])->values(),
        ]]);
    }
}
