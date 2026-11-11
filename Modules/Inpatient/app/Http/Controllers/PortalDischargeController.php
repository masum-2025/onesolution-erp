<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Discharge;
use Modules\Inpatient\Services\InpatientStore;
use Modules\Patients\Portal\PatientSubjects;

/**
 * A portal member's own discharge papers (B2B2C): the signed summary in force
 * of discharged stays of the patients the company linked to them. Never
 * drafts, earlier revisions, amendment reasons or the bill.
 */
class PortalDischargeController extends Controller
{
    private const LIMIT = 50;

    public function __construct(private InpatientStore $store, private PortalAccess $portal, private CurrentContext $context) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->context->organization();
        $open = $this->portal->isPortal() && in_array($company->type, [OrganizationType::Company, OrganizationType::Personal], true)
            && (bool) app(RuleResolver::class)->get('patients.portal_enabled', app(RuleContextFactory::class)->forOrganization($company));
        $ids = $open ? $this->portal->subjectIds(PatientSubjects::KEY) : [];
        $stays = $ids === [] ? collect() : $this->store->query(Admission::class, $company)->whereIn('patient_id', $ids)
            ->where('status', Admission::DISCHARGED)->orderByDesc('discharged_at')->limit(self::LIMIT)->get();
        $papers = $this->store->query(Discharge::class, $company)->whereIn('admission_id', $stays->pluck('id')->all())
            ->where('status', Discharge::SIGNED)->whereNull('superseded_at')->get()->keyBy(fn (Discharge $row) => (string) $row->admission_id);

        return response()->json(['data' => [
            'organization' => $company->displayName(),
            'discharges' => $stays->filter(fn (Admission $stay) => $papers->has((string) $stay->getKey()))
                ->map(fn (Admission $stay) => ['id' => $stay->getKey(), ...array_diff_key(
                    DischargeController::paperOf($company, $stay, $papers[(string) $stay->getKey()]),
                    array_flip(['revision', 'signed_by']),
                )])->values(),
        ]]);
    }
}
