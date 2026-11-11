<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Http\Requests\OutpatientActionRequest;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Serial;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Outpatient\Services\Serials;
use Modules\Outpatient\Services\SerialView;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * The desk's serials: a day's list (outpatient.view; a doctor limited to their
 * own patients sees only theirs), the sessions held that day and what a
 * serial would cost (outpatient.book), booking and moving serials on, and the
 * slip. Serials are those at the unit and below; anything else is one 404.
 */
class SerialController extends Controller
{
    use FindsOutpatient;

    /** Days ahead a serial may be given. */
    private const DAYS_AHEAD = 90;

    public function __construct(private OutpatientStore $store, private Serials $serials, private SerialView $view) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'doctor' => ['nullable', 'string', 'size:26'], 'chamber' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'in:'.implode(',', Serial::STATUSES)],
        ]);
        $date = $this->date($company, $filters['date'] ?? null);
        $own = $this->serials->onlyOwn($unit, $request->user());
        $list = $this->store->query(Serial::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->whereDate('date', $date->toDateString())
            ->when($own, fn ($q) => $q->whereIn('doctor_id', $this->serials->doctorsOf($company, $request->user())->all()))
            ->when(isset($filters['doctor']), fn ($q) => $q->where('doctor_id', $filters['doctor']))
            ->when(isset($filters['chamber']), fn ($q) => $q->where('chamber_id', $filters['chamber']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('schedule_id')->orderBy('serial_no')->limit(1000)->get();

        return response()->json([
            'data' => $this->view->rows($company, $list),
            'meta' => [
                'date' => $date->toDateString(), 'today' => $this->store->today($company)->toDateString(), 'own_only' => $own,
                'doctors' => $this->doctors($unit, $company, $own ? $this->serials->doctorsOf($company, $request->user())->all() : null),
                'can' => ['book' => Gate::allows('outpatient.book', $unit), 'consult' => Gate::allows('outpatient.consult', $unit), 'manage' => Gate::allows('outpatient.manage', $unit)],
            ],
        ]);
    }

    public function show(Request $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);

        return response()->json(['data' => $this->view->full($company, $this->find($request, $unit, $company, $serial))]);
    }

    /** The sessions held on a date, with free places (for booking). */
    public function sessions(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.book', $unit);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'doctor' => ['nullable', 'string', 'size:26']]);
        $date = $this->date($company, $filters['date'] ?? null);

        return response()->json(['data' => $this->serials->sessions($company, $unit, $date, $filters['doctor'] ?? null), 'meta' => ['date' => $date->toDateString()]]);
    }

    /** New or follow-up, free or the fee, for a patient in a session on a date (the chamber's branch rules). */
    public function quote(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.book', $unit);
        $data = $request->validate(['session' => ['required', 'string', 'size:26'], 'patient' => ['required', 'string', 'size:26'], 'date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->serials->quoteFor($company, $unit, $data['session'], $data['patient'], $this->date($company, $data['date'] ?? null))]);
    }

    public function store(OutpatientActionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.book', $unit);
        $data = $request->validated();
        $serial = $this->serials->book($company, $unit, $data['schedule_id'], $this->date($company, $data['date']), $data['patient_id'], $data['op_id'] ?? null, $request->user());

        return response()->json(['data' => $this->view->full($company, $serial->refresh())], $serial->wasRecentlyCreated ? 201 : 200);
    }

    public function move(OutpatientActionRequest $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);
        $found = $this->find($request, $unit, $company, $serial);
        $data = $request->validated(); // Who may move it (the desk, or the doctor for in and done) is checked in Serials::move.
        $moved = $this->serials->move($company, $unit, $found, $data['status'], $data['reason'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->view->full($company, $moved['serial']), 'meta' => ['fee_withdrawn' => $moved['fee_withdrawn']]]);
    }

    /** Patients to give a serial to: by patient number, mobile or name (through the Patients directory). */
    public function patients(Request $request, PatientDirectory $directory, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.book', $unit);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return response()->json(['data' => array_map(fn ($patient) => [
            'id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age, 'mobile' => $patient->mobile,
        ], $directory->search($company, $term, 15))]);
    }

    private function find(Request $request, Organization $unit, Organization $company, string $id): Serial
    {
        $serial = $this->store->query(Serial::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first();
        if ($serial !== null && $this->serials->onlyOwn($unit, $request->user()) && ! $this->serials->doctorsOf($company, $request->user())->contains((string) $serial->doctor_id)) {
            $serial = null;
        }

        return $serial ?? throw OutpatientException::notFound('serial');
    }

    /** The date asked for (today in the company's time zone when none), at most DAYS_AHEAD ahead. */
    private function date(Organization $company, ?string $value): CarbonImmutable
    {
        $today = $this->store->today($company);
        $date = $value === null ? $today : CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->store->timezone($company));
        if ($date->gt($today->addDays(self::DAYS_AHEAD))) {
            throw OutpatientException::because('too_far_ahead', 422, ['days' => self::DAYS_AHEAD]);
        }

        return $date;
    }

    /** @return list<array{id: string, name: string}> Doctors with sessions here, for the filter. */
    private function doctors(Organization $unit, Organization $company, ?array $only): array
    {
        $ids = collect($this->serials->sessionsDoctorIds($company, $unit));

        return $this->store->query(Doctor::class, $company)->whereKey($only === null ? $ids->all() : array_intersect($ids->all(), $only))->where('is_active', true)->get()
            ->sortBy(fn (Doctor $d) => $d->textIn('name'))->map(fn (Doctor $d) => ['id' => $d->getKey(), 'name' => $d->textIn('name')])->values()->all();
    }
}
