<?php

namespace Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Exceptions\PatientsException;
use Modules\Patients\Http\Controllers\Concerns\FindsPatients;
use Modules\Patients\Http\PatientsPresenter;
use Modules\Patients\Http\Requests\DecideMergeRequest;
use Modules\Patients\Http\Requests\MergePatientRequest;
use Modules\Patients\Models\MergeRequest;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccess;
use Modules\Patients\Services\PatientMerges;
use Modules\Patients\Services\PatientsStore;

/**
 * Merging duplicate patients (patients.manage): one person asks, another
 * decides. Requests are the company's.
 */
class MergeController extends Controller
{
    use FindsPatients;

    public function __construct(private PatientsStore $store, private PatientMerges $merges, private PatientsPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.manage', $unit);
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);
        $merges = $this->store->query(MergeRequest::class, $company)
            ->where('status', $filters['status'] ?? MergeRequest::PENDING)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $this->present($company, $merges->all())]);
    }

    public function store(MergePatientRequest $request, PatientAccess $access, string $organization, string $patient): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.manage', $unit);
        $source = $access->find($company, $unit, $request->user(), $patient);
        $target = $access->find($company, $unit, $request->user(), $request->validated('into_id'));
        $merge = $this->merges->request($company, $source, $target, $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->present($company, [$merge])[0]], 201);
    }

    public function decide(DecideMergeRequest $request, string $organization, string $merge): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patients.manage', $unit);
        $found = $this->store->query(MergeRequest::class, $company)->whereKey($merge)->first() ?? throw PatientsException::notFound('merge');
        $decided = $this->merges->decide($company, $found, (bool) $request->validated('approve'), $request->validated('note'), $request->user());

        return response()->json(['data' => $this->present($company, [$decided])[0]]);
    }

    /**
     * @param  list<MergeRequest>  $merges
     * @return list<array<string, mixed>>
     */
    private function present($company, array $merges): array
    {
        $ids = collect($merges)->flatMap(fn (MergeRequest $merge) => [$merge->source_id, $merge->target_id])->unique()->all();
        $patients = $this->store->query(Patient::class, $company)->whereKey($ids)->get()->keyBy('id')->all();
        $names = $this->presenter->userNames(collect($merges)->flatMap(fn (MergeRequest $merge) => array_filter([$merge->requested_by, $merge->decided_by]))->all());
        $today = $this->store->today($company);

        return array_map(fn (MergeRequest $merge) => $this->presenter->merge($merge, $patients, $names, $today), $merges);
    }
}
