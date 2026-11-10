<?php

namespace Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Http\Controllers\Concerns\FindsPatients;
use Modules\Patients\Http\PatientsPresenter;
use Modules\Patients\Http\Requests\PatientRequest;
use Modules\Patients\Models\PatientView;
use Modules\Patients\Models\TimelineEntry;
use Modules\Patients\Services\PatientAccess;
use Modules\Patients\Services\Patients;
use Modules\Patients\Services\PatientSearch;
use Modules\Patients\Services\PatientsStore;

/**
 * Patients: found (number, mobile, name) and opened with patients.view,
 * registered with patients.register, changed with patients.manage, the
 * "who looked" log read with patients.audit. Opening a record, its history
 * or its card is written to that log. Only patients the person may see
 * (PatientAccess) are found; anything else is the same 404.
 */
class PatientController extends Controller
{
    use FindsPatients;

    public function __construct(
        private PatientsStore $store,
        private PatientAccess $access,
        private Patients $patients,
        private PatientsPresenter $presenter,
    ) {}

    public function index(Request $request, PatientSearch $search, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $search->apply($company, $this->access->visible($company, $unit, $request->user()), (string) ($filters['q'] ?? ''))
            ->paginate(PerPage::from($request, 20));
        $today = $this->store->today($company);

        return response()->json([
            'data' => collect($page->items())->map(fn ($patient) => $this->presenter->summary($patient, $today))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, string $organization, string $patient): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.view', $unit);
        $found = $this->access->find($company, $unit, $request->user(), $patient);
        $this->access->logView($company, $found, $request->user(), 'record');

        return response()->json(['data' => $this->presenter->patient($found, $this->store->today($company), Gate::allows('patients.manage', $unit))]);
    }

    public function store(PatientRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.register', $unit);
        $patient = $this->patients->register($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->patient($patient, $this->store->today($company), Gate::allows('patients.manage', $unit))], $patient->wasRecentlyCreated ? 201 : 200);
    }

    public function update(PatientRequest $request, string $organization, string $patient): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.view', $unit);
        $found = $this->access->find($company, $unit, $request->user(), $patient);
        Gate::authorize('patients.manage', $unit);
        $changed = $this->patients->update($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->patient($changed, $this->store->today($company), true)]);
    }

    public function timeline(Request $request, string $organization, string $patient): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.view', $unit);
        $found = $this->access->find($company, $unit, $request->user(), $patient);
        $this->access->logView($company, $found, $request->user(), 'history');
        $entries = $this->store->query(TimelineEntry::class, $company)->where('patient_id', $found->getKey())
            ->orderByDesc('happened_at')->orderByDesc('id')->limit(500)->get();
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'patient' => $this->presenter->summary($found, $this->store->today($company)),
            'entries' => $entries->map(fn (TimelineEntry $entry) => $this->presenter->timeline($entry, $locale))->values(),
        ]]);
    }

    /** What the printed card shows; the QR code holds the patient number only. */
    public function card(Request $request, string $organization, string $patient): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.view', $unit);
        $found = $this->access->find($company, $unit, $request->user(), $patient);
        $this->access->logView($company, $found, $request->user(), 'card');
        $svg = (new Writer(new ImageRenderer(new RendererStyle(160, 0), new SvgImageBackEnd)))->writeString($found->mrn);

        return response()->json(['data' => [
            ...$this->presenter->summary($found, $this->store->today($company)),
            'blood_group' => $found->blood_group,
            'organization' => $company->displayName(),
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ]]);
    }

    /** Who opened the record, newest first (patients.audit). */
    public function views(Request $request, string $organization, string $patient): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.audit', $unit);
        $found = $this->access->find($company, $unit, $request->user(), $patient);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = $this->store->query(PatientView::class, $company)->where('patient_id', $found->getKey())
            ->orderByDesc('viewed_at')->orderByDesc('id')->paginate(50);
        $names = $this->presenter->userNames(collect($page->items())->pluck('user_id')->all());

        return response()->json([
            'data' => collect($page->items())->map(fn (PatientView $view) => $this->presenter->view($view, $names))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
