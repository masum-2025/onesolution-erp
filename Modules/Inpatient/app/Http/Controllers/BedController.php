<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Http\Controllers\Concerns\FindsInpatient;
use Modules\Inpatient\Http\Requests\InpatientActionRequest;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\Ward;
use Modules\Inpatient\Services\Admissions;
use Modules\Inpatient\Services\Beds;
use Modules\Inpatient\Services\InpatientStore;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Beds at the unit and below: the board (every bed by ward, its state, and
 * for an occupied one the patient and number; outpatient.view), beds kept by
 * managers, and a bed marked clean or blocked by the admission desk.
 */
class BedController extends Controller
{
    use FindsInpatient;

    public function __construct(private InpatientStore $store, private Beds $beds, private Admissions $admissions) {}

    public function board(Request $request, PatientDirectory $patients, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $units = $this->store->subtreeIds($unit);
        $wards = $this->store->query(Ward::class, $company)->whereIn('unit_id', $units)->where('is_active', true)->get()->sortBy(fn (Ward $ward) => $ward->textIn('name'))->values();
        $beds = $this->store->query(Bed::class, $company)->whereIn('ward_id', $wards->pluck('id')->all())->where('is_active', true)->orderBy('code')->get();
        $own = $this->admissions->onlyOwn($unit, $request->user());
        $stays = $this->store->query(Admission::class, $company)->whereKey($beds->pluck('admission_id')->filter()->all())->get()->keyBy(fn ($a) => (string) $a->getKey());

        return response()->json(['data' => $wards->map(fn (Ward $ward) => [
            'id' => $ward->getKey(), 'name' => $ward->textIn('name'), 'kind' => $ward->kind,
            'beds' => $beds->where('ward_id', $ward->getKey())->map(function (Bed $bed) use ($stays, $own, $request, $company, $patients) {
                $stay = $bed->admission_id === null ? null : $stays->get((string) $bed->admission_id);
                // A doctor limited to their own patients sees other beds as taken, without names.
                $shown = $stay !== null && (! $own || (string) $stay->doctor_user_id === (string) $request->user()->getKey());
                $patient = $shown ? $patients->find($company, $stay->patient_id) : null;

                return [
                    'id' => $bed->getKey(), 'code' => $bed->code, 'status' => $bed->status, 'version' => $bed->version,
                    'admission' => $shown ? ['id' => $stay->getKey(), 'number' => $stay->number, 'patient' => $patient?->name, 'mrn' => $patient?->mrn, 'admitted_at' => $stay->admitted_at->toIso8601String()] : null,
                ];
            })->values(),
        ])->values(), 'meta' => ['can' => ['admit' => Gate::allows('inpatient.admit', $unit), 'manage' => Gate::allows('inpatient.manage', $unit)]]]);
    }

    /** Every bed of the unit and below, for managers (with those switched off). */
    public function index(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $beds = $this->store->query(Bed::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->orderBy('ward_id')->orderBy('code')->limit(2000)->get();

        return response()->json(['data' => $beds->map(fn (Bed $bed) => $this->row($bed))->values()]);
    }

    public function store(InpatientActionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.manage', $unit);

        return response()->json(['data' => $this->row($this->beds->save($company, $unit, null, $request->validated(), null, $request->user()))], 201);
    }

    public function update(InpatientActionRequest $request, string $organization, string $bed): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $bed);
        Gate::authorize('inpatient.manage', $unit);
        $data = $request->validated();

        return response()->json(['data' => $this->row($this->beds->save($company, $unit, $found, $data, (int) $data['base_version'], $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $bed): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $bed);
        Gate::authorize('inpatient.manage', $unit);
        $this->beds->delete($company, $found, (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'], $request->user());

        return response()->noContent();
    }

    public function status(InpatientActionRequest $request, string $organization, string $bed): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $bed);
        if (! Gate::any(['inpatient.admit', 'inpatient.manage'], $unit)) {
            abort(403);
        }
        $data = $request->validated();

        return response()->json(['data' => $this->row($this->beds->setStatus($company, $found, $data['status'], (int) $data['base_version'], $request->user()))]);
    }

    private function find(Organization $unit, Organization $company, string $id): Bed
    {
        return $this->store->query(Bed::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first() ?? throw InpatientException::notFound('bed');
    }

    /** @return array<string, mixed> */
    private function row(Bed $bed): array
    {
        return ['id' => $bed->getKey(), 'ward_id' => $bed->ward_id, 'code' => $bed->code, 'service_code' => $bed->service_code, 'status' => $bed->status, 'is_active' => $bed->is_active, 'version' => $bed->version];
    }
}
