<?php

namespace Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Http\Controllers\Concerns\FindsPatients;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccess;
use Modules\Patients\Services\PatientsStore;

/**
 * What the patient screens need once (patients.view): what the person may
 * do, the choices for the form, whether an ID number is required, and
 * whether they see only their own patients.
 */
class SetupController extends Controller
{
    use FindsPatients;

    public function __construct(private PatientsStore $store, private PatientAccess $access) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.view', $unit);

        return response()->json(['data' => [
            'can' => [
                'register' => Gate::allows('patients.register', $unit),
                'manage' => Gate::allows('patients.manage', $unit),
                'audit' => Gate::allows('patients.audit', $unit),
            ],
            'country' => $this->store->country($company),
            'require_id_number' => (bool) $this->store->rule('require_id_number', $unit),
            'only_own' => $this->access->onlyOwn($unit, $request->user()),
            'options' => [
                'sexes' => Patient::SEXES,
                'id_types' => Patient::ID_TYPES,
                'blood_groups' => Patient::BLOOD_GROUPS,
            ],
        ]]);
    }
}
