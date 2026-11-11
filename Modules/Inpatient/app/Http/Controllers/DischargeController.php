<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Http\Controllers\Concerns\FindsInpatient;
use Modules\Inpatient\Http\Requests\InpatientActionRequest;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\Discharge;
use Modules\Inpatient\Models\Ward;
use Modules\Inpatient\Services\Admissions;
use Modules\Inpatient\Services\Discharges;
use Modules\Inpatient\Services\InpatientStore;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * A stay's discharge: the summary and its revisions with what the bill comes
 * to if the patient leaves now (read by those who give care), writing the
 * summary, discharging and amending (inpatient.discharge), and the paper to
 * print in the rule's language.
 */
class DischargeController extends Controller
{
    use FindsInpatient;

    public function __construct(private InpatientStore $store, private Admissions $admissions, private Discharges $discharges, private PatientDirectory $patients) {}

    public function show(Request $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission);
        $current = $this->discharges->current($company, $found);
        $revisions = $this->store->query(Discharge::class, $company)->where('admission_id', $found->getKey())->where('status', Discharge::SIGNED)->orderBy('revision')->get();

        return response()->json(['data' => [
            'admission' => ['id' => $found->getKey(), 'number' => $found->number, 'status' => $found->status, 'version' => $found->version, 'bill_id' => $found->bill_id],
            'summary' => $current === null ? null : self::summaryOf($current),
            'revisions' => $revisions->map(fn (Discharge $row) => [
                'revision' => $row->revision, 'signed_at' => $row->signed_at?->toIso8601String(), 'amend_reason' => $row->amend_reason, 'superseded' => $row->superseded_at !== null,
            ])->values(),
            'preview' => $found->status === Admission::ADMITTED ? $this->discharges->preview($company, $found) : null,
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'outcomes' => Discharge::OUTCOMES,
            'can' => ['discharge' => Gate::allows('inpatient.discharge', $unit)],
        ]]);
    }

    public function save(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission, 'inpatient.discharge');
        $data = $request->validated();
        $saved = $this->discharges->save($company, $found, $data, isset($data['base_version']) ? (int) $data['base_version'] : null, $request->user());

        return response()->json(['data' => self::summaryOf($saved)], $saved->wasRecentlyCreated ? 201 : 200);
    }

    public function finish(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission, 'inpatient.discharge');
        $done = $this->discharges->discharge($company, $found, (int) $request->validated()['base_version'], $request->user());

        return response()->json(['data' => ['id' => $done->getKey(), 'status' => $done->status, 'discharged_at' => $done->discharged_at?->toIso8601String(), 'version' => $done->version]]);
    }

    public function amend(InpatientActionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission, 'inpatient.discharge');
        $data = $request->validated();

        return response()->json(['data' => self::summaryOf($this->discharges->amend($company, $found, $data, $data['reason'], $request->user()))], 201);
    }

    /** The signed summary in force with the stay, the patient and the doctor, to print. */
    public function paper(Request $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->stay($request, $organization, $admission);
        $summary = $this->discharges->inForce($company, $found) ?? throw InpatientException::notFound('discharge');

        return response()->json(['data' => $this->paperOf($company, $found, $summary)]);
    }

    /**
     * What the printed discharge shows (also the portal's).
     *
     * @return array<string, mixed>
     */
    public static function paperOf(Organization $company, Admission $admission, Discharge $summary): array
    {
        $store = app(InpatientStore::class);
        $patient = app(PatientDirectory::class)->find($company, $admission->patient_id);
        $bed = $store->query(Bed::class, $company)->find($admission->bed_id);
        $ward = $bed === null ? null : $store->query(Ward::class, $company)->find($bed->ward_id);
        $unit = Organization::query()->find($admission->unit_id) ?? $company;

        return [
            'number' => $admission->number,
            'patient' => $patient === null ? null : ['name' => $patient->name, 'mrn' => $patient->mrn, 'sex' => $patient->sex, 'age' => $patient->age],
            'doctor' => User::query()->whereKey($admission->doctor_user_id)->value('name'),
            'bed' => $bed === null ? null : ['code' => $bed->code, 'ward' => $ward?->getAttribute('name')],
            'admitted_at' => $admission->admitted_at->toIso8601String(), 'discharged_at' => $admission->discharged_at?->toIso8601String(),
            'reason' => $admission->reason,
            ...array_intersect_key(self::summaryOf($summary), array_flip([...Discharge::TEXTS, 'revision', 'signed_at'])),
            'signed_by' => User::query()->whereKey($summary->signed_by)->value('name'),
            'unit' => $unit->displayName(),
            'language' => (string) $store->rule('discharge_language', $unit),
        ];
    }

    /** @return array<string, mixed> */
    public static function summaryOf(Discharge $row): array
    {
        return [
            'id' => $row->getKey(), 'revision' => $row->revision, 'status' => $row->status,
            'diagnosis' => $row->diagnosis, 'treatment' => $row->treatment, 'advice' => $row->advice,
            'follow_up_on' => $row->follow_up_on?->toDateString(), 'outcome' => $row->outcome,
            'amend_reason' => $row->amend_reason, 'signed_at' => $row->signed_at?->toIso8601String(), 'version' => $row->version,
        ];
    }

    /**
     * The stay (404 when not visible), and the permission: one who gives care when none is named.
     *
     * @return array{0: Organization, 1: Organization, 2: Admission}
     */
    private function stay(Request $request, string $organization, string $admission, ?string $permission = null): array
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $found = $this->admissions->visible($company, $unit, $request->user(), $admission);
        if ($permission !== null) {
            Gate::authorize($permission, $unit);
        } elseif (! Gate::any(CareController::READERS, $unit)) {
            throw InpatientException::because('care_only', 403);
        }

        return [$unit, $company, $found];
    }
}
