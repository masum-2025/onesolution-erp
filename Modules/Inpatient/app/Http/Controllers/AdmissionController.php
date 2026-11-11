<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Inpatient\Http\Controllers\Concerns\FindsInpatient;
use Modules\Inpatient\Http\Requests\InpatientActionRequest;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\BedCharge;
use Modules\Inpatient\Models\BedMove;
use Modules\Inpatient\Models\Ward;
use Modules\Inpatient\Services\Admissions;
use Modules\Inpatient\Services\InpatientStore;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Admissions at the unit and below: the list by status and one stay (with
 * its bed moves and bed days; inpatient.view, a doctor limited to their own
 * sees only theirs), admitting, moving a bed and cancelling (inpatient.admit),
 * and what the admission screen needs (staff who may attend, the patient's
 * advance, patients by number, mobile or name).
 */
class AdmissionController extends Controller
{
    use FindsInpatient;

    public function __construct(private InpatientStore $store, private Admissions $admissions, private PatientDirectory $patients, private PatientCharges $charges) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', Admission::STATUSES)], 'q' => ['nullable', 'string', 'max:30']]);
        $own = $this->admissions->onlyOwn($unit, $request->user());
        $term = strtoupper(trim((string) ($filters['q'] ?? '')));
        $list = $this->store->query(Admission::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->where('status', $filters['status'] ?? Admission::ADMITTED)
            ->when($own, fn ($q) => $q->where('doctor_user_id', $request->user()->getKey()))
            ->when($term !== '', fn ($q) => $q->where('number', $term))
            ->orderByDesc('admitted_at')->limit(500)->get();

        return response()->json(['data' => $this->rows($company, $list), 'meta' => [
            'own_only' => $own, 'can' => ['admit' => Gate::allows('inpatient.admit', $unit), 'manage' => Gate::allows('inpatient.manage', $unit)],
        ]]);
    }

    public function show(Request $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $found = $this->admissions->visible($company, $unit, $request->user(), $admission);
        $beds = $this->beds($company);
        $moves = $this->store->query(BedMove::class, $company)->where('admission_id', $found->getKey())->orderBy('moved_at')->get();
        $days = $this->store->query(BedCharge::class, $company)->where('admission_id', $found->getKey())->whereNull('withdrawn_at')->orderBy('day_no')->get();

        return response()->json(['data' => [
            ...$this->rows($company, collect([$found]))[0],
            'reason' => $found->reason, 'guardian_name' => $found->guardian_name, 'guardian_mobile' => $found->guardian_mobile, 'cancel_reason' => $found->cancel_reason,
            'bill_due_minor' => $found->bill_id === null ? null : $this->charges->dueOn($company, $found->bill_id),
            'moves' => $moves->map(fn (BedMove $move) => ['from' => $beds[(string) $move->from_bed_id] ?? null, 'to' => $beds[(string) $move->to_bed_id] ?? null, 'moved_at' => $move->moved_at->toIso8601String(), 'reason' => $move->reason])->values(),
            'bed_days' => $days->map(fn (BedCharge $day) => ['day_no' => $day->day_no, 'day_on' => $day->day_on->toDateString(), 'bed' => $beds[(string) $day->bed_id] ?? null, 'service_code' => $day->service_code])->values(),
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'can' => ['admit' => Gate::allows('inpatient.admit', $unit), 'round' => Gate::allows('inpatient.round', $unit), 'nurse' => Gate::allows('inpatient.nurse', $unit), 'discharge' => Gate::allows('inpatient.discharge', $unit)],
        ]]);
    }

    /** What admitting needs: staff who may attend and, for a patient, their advance. */
    public function options(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.admit', $unit);
        $patient = $request->validate(['patient' => ['nullable', 'string', 'size:26']])['patient'] ?? null;

        return response()->json(['data' => [
            'doctors' => $this->admissions->staff($company)->map(fn ($user) => ['id' => $user->getKey(), 'name' => $user->name])->values(),
            'advance_minor' => $patient === null ? null : $this->charges->creditOf($company, $patient),
            'min_advance_minor' => $this->store->minAdvance($company, $unit),
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
        ]]);
    }

    public function patients(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.admit', $unit);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return response()->json(['data' => array_map(fn ($patient) => [
            'id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age, 'mobile' => $patient->mobile,
        ], $this->patients->search($company, $term, 15))]);
    }

    public function store(InpatientActionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.admit', $unit);
        $admission = $this->admissions->admit($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->rows($company, collect([$admission]))[0]], $admission->wasRecentlyCreated ? 201 : 200);
    }

    public function move(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->admissions->visible($company, $unit, $request->user(), $admission);
        Gate::authorize('inpatient.admit', $unit);
        $data = $request->validated();
        $moved = $this->admissions->move($company, $unit, $found, $data['bed_id'], $data['reason'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->rows($company, collect([$moved]))[0]]);
    }

    public function cancel(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->admissions->visible($company, $unit, $request->user(), $admission);
        Gate::authorize('inpatient.admit', $unit);
        $data = $request->validated();

        return response()->json(['data' => $this->rows($company, collect([$this->admissions->cancel($company, $found, $data['reason'], (int) $data['base_version'], $request->user())]))[0]]);
    }

    /**
     * @param  Collection<int, Admission>  $list
     * @return list<array<string, mixed>>
     */
    private function rows(Organization $company, Collection $list): array
    {
        $beds = $this->beds($company);
        $doctors = User::query()->whereKey($list->pluck('doctor_user_id')->unique()->all())->pluck('name', 'id');
        $days = $this->store->query(BedCharge::class, $company)->whereIn('admission_id', $list->pluck('id')->all())->whereNull('withdrawn_at')->get(['admission_id'])->countBy(fn ($c) => (string) $c->admission_id);

        return $list->map(function (Admission $admission) use ($company, $beds, $doctors, $days) {
            $patient = $this->patients->find($company, $admission->patient_id);

            return [
                'id' => $admission->getKey(), 'number' => $admission->number, 'status' => $admission->status, 'unit_id' => $admission->unit_id,
                'patient' => $patient === null ? null : ['id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age],
                'doctor' => ['id' => $admission->doctor_user_id, 'name' => $doctors[(string) $admission->doctor_user_id] ?? null],
                'bed' => $beds[(string) $admission->bed_id] ?? null,
                'admitted_at' => $admission->admitted_at->toIso8601String(), 'discharged_at' => $admission->discharged_at?->toIso8601String(),
                'bed_days' => $days->get((string) $admission->getKey(), 0), 'bill_id' => $admission->bill_id, 'version' => $admission->version,
            ];
        })->values()->all();
    }

    /** @return array<string, array{id: string, code: string, ward: string}> The company's beds with their ward's name. */
    private function beds(Organization $company): array
    {
        $wards = $this->store->query(Ward::class, $company)->get()->mapWithKeys(fn (Ward $ward) => [(string) $ward->getKey() => $ward->textIn('name')]);

        return $this->store->query(Bed::class, $company)->get(['id', 'ward_id', 'code'])
            ->mapWithKeys(fn (Bed $bed) => [(string) $bed->getKey() => ['id' => (string) $bed->getKey(), 'code' => $bed->code, 'ward' => $wards[(string) $bed->ward_id] ?? '']])->all();
    }
}
