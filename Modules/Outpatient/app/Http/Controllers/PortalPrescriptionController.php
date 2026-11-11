<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Modules\Outpatient\Models\Consultation;
use Modules\Outpatient\Models\Serial;
use Modules\Outpatient\Services\ConsultationView;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Patients\Portal\PatientSubjects;

/**
 * A portal member's own prescriptions (B2B2C): finished consultations in
 * force of the patients the company linked to them. Never drafts, earlier
 * revisions, who wrote them or the lab order.
 */
class PortalPrescriptionController extends Controller
{
    private const LIMIT = 50;

    public function __construct(private OutpatientStore $store, private PortalAccess $portal, private CurrentContext $context, private ConsultationView $view) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->context->organization();
        $open = $this->portal->isPortal() && in_array($company->type, [OrganizationType::Company, OrganizationType::Personal], true)
            && (bool) app(RuleResolver::class)->get('patients.portal_enabled', app(RuleContextFactory::class)->forOrganization($company));
        $ids = $open ? $this->portal->subjectIds(PatientSubjects::KEY) : [];
        $visits = $ids === [] ? collect() : $this->store->query(Consultation::class, $company)->whereIn('patient_id', $ids)
            ->where('status', Consultation::FINISHED)->whereNull('superseded_at')->orderByDesc('finished_at')->limit(self::LIMIT)->get();
        $dates = $this->store->query(Serial::class, $company)->whereKey($visits->pluck('serial_id')->all())->get(['id', 'date'])->keyBy(fn (Serial $serial) => (string) $serial->getKey());

        return response()->json(['data' => [
            'organization' => $company->displayName(),
            'prescriptions' => $visits->map(fn (Consultation $visit) => [
                ...array_diff_key($this->view->full($company, $visit), array_flip(['lab_order_id', 'version', 'superseded', 'amend_reason', 'serial_id'])),
                'date' => $dates->get((string) $visit->serial_id)?->date?->toDateString(),
                'doctor' => $this->view->doctor($company, $visit->doctor_id),
                'language' => (string) $this->store->rule('prescription_language', Organization::query()->find($visit->unit_id) ?? $company),
            ])->values(),
        ]]);
    }
}
