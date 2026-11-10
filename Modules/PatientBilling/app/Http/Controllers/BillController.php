<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Http\Controllers\Concerns\FindsPatientBilling;
use Modules\PatientBilling\Http\PatientBillingPresenter;
use Modules\PatientBilling\Http\Requests\BillActionRequest;
use Modules\PatientBilling\Http\Requests\BillRequest;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\BillLine;
use Modules\PatientBilling\Models\DiscountRequest;
use Modules\PatientBilling\Models\Panel;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Services\Bills;
use Modules\PatientBilling\Services\PatientBillingStore;
use Modules\PatientBilling\Services\Payments;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Bills of the unit and the units below it: listed and read with
 * patient_billing.view; opened, filled, discounted, finalized and voided
 * with patient_billing.collect. Anything outside the unit is the same 404.
 */
class BillController extends Controller
{
    use FindsPatientBilling;

    public function __construct(
        private PatientBillingStore $store,
        private Bills $bills,
        private PatientDirectory $patients,
        private PatientBillingPresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.view', $unit);
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,final,void,due'], 'patient' => ['nullable', 'string', 'size:26'], 'q' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->store->query(Bill::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(($filters['status'] ?? null) === 'due', fn ($query) => $query->where('status', Bill::FINAL)->whereColumn('paid_minor', '<', 'total_minor'))
            ->when(in_array($filters['status'] ?? null, ['open', 'final', 'void'], true), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['patient']), fn ($query) => $query->where('patient_id', $filters['patient']))
            ->when(isset($filters['q']), fn ($query) => $query->where('number', strtoupper(trim($filters['q']))))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));
        $patients = $this->patientsOf($company, collect($page->items())->pluck('patient_id')->all());

        return response()->json([
            'data' => collect($page->items())->map(fn (Bill $bill) => $this->presenter->bill($bill, $patients[$bill->patient_id] ?? null))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.view', $unit);

        return response()->json(['data' => $this->full($company, $this->find($unit, $company, $bill), $payments)]);
    }

    public function store(BillRequest $request, Payments $payments, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.collect', $unit);
        $bill = $this->bills->create($company, $unit, $request->validated('patient_id'), $request->validated('panel_id'), $request->validated('op_id'), $request->user());

        return response()->json(['data' => $this->full($company, $bill, $payments)], $bill->wasRecentlyCreated ? 201 : 200);
    }

    public function addLine(BillActionRequest $request, Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company, $found] = $this->forWriting($organization, $bill);
        $this->bills->addLine($company, $found, $request->validated('service_id'), (int) ($request->validated('quantity') ?? 1) * 1000, $request->user());

        return response()->json(['data' => $this->full($company, $found->refresh(), $payments)], 201);
    }

    public function voidLine(Request $request, Payments $payments, string $organization, string $bill, string $line): JsonResponse
    {
        [$unit, $company, $found] = $this->forWriting($organization, $bill);

        return response()->json(['data' => $this->full($company, $this->bills->voidLine($company, $found, $line, $request->user()), $payments)]);
    }

    public function panel(BillActionRequest $request, Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company, $found] = $this->forWriting($organization, $bill);
        $changed = $this->bills->setPanel($company, $found, $request->validated('panel_id'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->full($company, $changed, $payments)]);
    }

    public function discount(BillActionRequest $request, Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company, $found] = $this->forWriting($organization, $bill);
        [$changed, $waiting] = $this->bills->discount($company, $unit, $found, (int) $request->validated('amount_minor'), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->full($company, $changed, $payments), 'waiting_approval' => $waiting !== null], $waiting !== null ? 202 : 200);
    }

    public function finalize(BillActionRequest $request, Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company, $found] = $this->forWriting($organization, $bill);

        return response()->json(['data' => $this->full($company, $this->bills->finalize($company, $unit, $found, (int) $request->validated('base_version'), $request->user()), $payments)]);
    }

    public function void(BillActionRequest $request, Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company, $found] = $this->forWriting($organization, $bill);
        $voided = $this->bills->void($company, $found, $request->validated('reason'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->full($company, $voided, $payments)]);
    }

    /** What the printed bill and money receipt show. */
    public function receipt(Payments $payments, string $organization, string $bill): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.view', $unit);

        return response()->json(['data' => [
            ...$this->full($company, $this->find($unit, $company, $bill), $payments),
            'organization' => $company->displayName(),
            'branch' => Organization::query()->find($this->find($unit, $company, $bill)->unit_id)?->displayName(),
        ]]);
    }

    /** @return array{0: Organization, 1: Organization, 2: Bill} */
    private function forWriting(string $organization, string $bill): array
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $bill);
        Gate::authorize('patient_billing.collect', $unit);

        return [$unit, $company, $found];
    }

    private function find(Organization $unit, Organization $company, string $id): Bill
    {
        return $this->store->query(Bill::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PatientBillingException::notFound('bill');
    }

    /** @return array<string, mixed> */
    private function full(Organization $company, Bill $bill, Payments $payments): array
    {
        $bill->refresh();
        $locale = app()->getLocale();
        $lines = $this->store->query(BillLine::class, $company)->where('bill_id', $bill->getKey())->whereNull('voided_at')->orderBy('created_at')->orderBy('id')->get();
        $money = $this->store->query(Payment::class, $company)->where('bill_id', $bill->getKey())->orderBy('received_at')->orderBy('id')->get();
        $requests = $this->store->query(DiscountRequest::class, $company)->where('bill_id', $bill->getKey())->orderByDesc('created_at')->get();
        $names = $this->presenter->userNames([...$money->pluck('received_by')->all(), ...$requests->pluck('requested_by')->all(), ...$requests->pluck('decided_by')->all()]);
        $panel = $bill->panel_id === null ? null : $this->store->query(Panel::class, $company)->whereKey($bill->panel_id)->value('name');

        return [
            ...$this->presenter->bill($bill, $this->presenter->patient($this->patients->find($company, $bill->patient_id)), $panel),
            'lines' => $lines->map(fn (BillLine $line) => $this->presenter->line($line, $locale))->values(),
            'payments' => $money->map(fn (Payment $payment) => $this->presenter->payment($payment, $names))->values(),
            'discount_requests' => $requests->map(fn (DiscountRequest $request) => $this->presenter->discountRequest($request, $names))->values(),
            'advance_balance_minor' => $payments->advanceBalance($company, $bill->patient_id),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>|null>
     */
    private function patientsOf(Organization $company, array $ids): array
    {
        $out = [];
        foreach (array_unique($ids) as $id) {
            $out[$id] = $this->presenter->patient($this->patients->find($company, $id));
        }

        return $out;
    }
}
