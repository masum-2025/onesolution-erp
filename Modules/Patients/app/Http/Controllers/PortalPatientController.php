<?php

namespace Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Modules\Patients\Http\PatientsPresenter;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\TimelineEntry;
use Modules\Patients\Portal\PatientSubjects;
use Modules\Patients\Services\PatientsStore;

/**
 * A portal member's own patients (themselves, or the children they are the
 * guardian of) and their history (B2B2C): only patients the company linked
 * to them, only while patients.portal_enabled is on. Never the ID number,
 * allergies, address or who looked.
 */
class PortalPatientController extends Controller
{
    public function __construct(private PatientsStore $store, private PortalAccess $portal, private CurrentContext $context, private PatientsPresenter $presenter) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->context->organization();
        $open = $this->portal->isPortal()
            && in_array($company->type, [OrganizationType::Company, OrganizationType::Personal], true)
            && $this->store->rule('portal_enabled', $company);
        $ids = $open ? $this->portal->subjectIds(PatientSubjects::KEY) : [];
        $patients = $ids === [] ? collect() : $this->store->query(Patient::class, $company)->whereKey($ids)->whereNull('merged_into_id')->orderBy('name')->get();
        $entries = $patients->isEmpty() ? collect() : $this->store->query(TimelineEntry::class, $company)->whereIn('patient_id', $patients->modelKeys())
            ->orderByDesc('happened_at')->orderByDesc('id')->limit(300)->get()->groupBy('patient_id');
        $today = $this->store->today($company);
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'organization' => $company->displayName(),
            'enabled' => (bool) $open,
            'patients' => $patients->map(fn (Patient $patient) => [
                'id' => $patient->getKey(), 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex,
                'age' => $patient->ageOn($today), 'blood_group' => $patient->blood_group,
                'history' => ($entries[$patient->getKey()] ?? collect())->map(fn (TimelineEntry $entry) => $this->presenter->timeline($entry, $locale))->values(),
            ])->values(),
        ]]);
    }
}
