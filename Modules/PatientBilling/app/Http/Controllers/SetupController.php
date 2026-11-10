<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Services\TaxCodes;
use Modules\PatientBilling\Http\Controllers\Concerns\FindsPatientBilling;
use Modules\PatientBilling\Models\Panel;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Models\Service;
use Modules\PatientBilling\Services\BillingPostings;
use Modules\PatientBilling\Services\PatientBillingStore;

/**
 * What the billing screens need once (patient_billing.view): what the person
 * may do, the company's currency, the rules the screens explain, the active
 * services and panels to pick, the ways to pay, and the sales tax codes when
 * the company keeps books.
 */
class SetupController extends Controller
{
    use FindsPatientBilling;

    public function __construct(private PatientBillingStore $store, private BillingPostings $postings) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.view', $unit);

        return response()->json(['data' => [
            'can' => [
                'collect' => Gate::allows('patient_billing.collect', $unit),
                'approve' => Gate::allows('patient_billing.approve', $unit),
                'manage' => Gate::allows('patient_billing.manage', $unit),
                'close_day' => Gate::allows('patient_billing.close_day', $unit),
            ],
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'rules' => [
                'discount_approval_above_percent' => (int) $this->store->rule('discount_approval_above_percent', $unit),
                'allow_due' => (bool) $this->store->rule('allow_due', $unit),
                'require_cash_day' => (bool) $this->store->rule('require_cash_day', $unit),
                'prices_include_tax' => (bool) $this->store->rule('prices_include_tax', $unit),
            ],
            'methods' => Payment::METHODS,
            'options' => [
                'service_id' => $this->store->query(Service::class, $company)->where('is_active', true)->orderBy('code')->get()
                    ->map(fn (Service $service) => ['id' => $service->getKey(), 'code' => $service->code, 'name' => $service->textIn('name'), 'category' => $service->category, 'price_minor' => $service->price_minor])->values(),
                'panel_id' => $this->store->query(Panel::class, $company)->where('is_active', true)->orderBy('name')->get()
                    ->map(fn (Panel $panel) => ['id' => $panel->getKey(), 'name' => $panel->name, 'kind' => $panel->kind, 'discount_percent' => $panel->discount_percent])->values(),
                'tax_code_id' => $codes = $this->postings->keepsBooks($company) ? app(TaxCodes::class)->salesCodes($company) : [],
                // The generated service form reads link choices as id => title.
                'tax_code' => (object) collect($codes)->mapWithKeys(fn (array $code) => [$code['id'] => "{$code['code']} · {$code['name']}"])->all(),
            ],
        ]]);
    }
}
