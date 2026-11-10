<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\ReportView;
use Modules\Patients\Portal\PatientSubjects;

/**
 * A portal member's own lab reports (B2B2C): only verified (or delivered)
 * orders of the patients the company linked to them, verified values only.
 * Never samples, notes, bills or who entered the results.
 */
class PortalReportController extends Controller
{
    public function __construct(private DiagnosticsStore $store, private PortalAccess $portal, private CurrentContext $context, private ReportView $view) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->context->organization();
        $open = $this->portal->isPortal() && in_array($company->type, [OrganizationType::Company, OrganizationType::Personal], true)
            && $this->portalAllowed($company);
        $ids = $open ? $this->portal->subjectIds(PatientSubjects::KEY) : [];
        $orders = $ids === [] ? collect() : $this->store->query(Order::class, $company)->whereIn('patient_id', $ids)->whereIn('status', ['verified', 'delivered'])
            ->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => [
            'organization' => $company->displayName(),
            'reports' => $orders->map(fn (Order $order) => array_diff_key($this->view->full($company, $order, verifiedOnly: true), array_flip(['bill_id', 'bill_due_minor', 'samples', 'notes', 'version', 'unit_id'])))->values(),
        ]]);
    }

    /** The patients module decides whether patients see anything in the portal. */
    private function portalAllowed($company): bool
    {
        return (bool) app(RuleResolver::class)->get('patients.portal_enabled', app(RuleContextFactory::class)->forOrganization($company));
    }
}
