<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Http\OutpatientPresenter;
use Modules\Outpatient\Http\Requests\DoctorRequest;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Services\Doctors;
use Modules\Outpatient\Services\OutpatientStore;

/**
 * Doctors of the unit and the units below it: listed (search, filters, pages) and read with
 * outpatient.view; made, changed and removed with outpatient.manage.
 */
class DoctorController extends Controller
{
    use FindsOutpatient;

    public function __construct(private OutpatientStore $store, private Doctors $records, private OutpatientPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Doctor::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('registration_no', 'like', $like)->orWhere('specialty', 'like', $like)->orWhere('mobile', 'like', $like));
            })
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Doctor $record) => $this->presenter->doctor($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $doctor): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);

        return response()->json(['data' => $this->presenter->doctor($this->find($unit, $company, $doctor))]);
    }

    public function store(DoctorRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->doctor($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(DoctorRequest $request, string $organization, string $doctor): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $doctor);
        Gate::authorize('outpatient.manage', $unit);

        return response()->json(['data' => $this->presenter->doctor($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $doctor): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $doctor);
        Gate::authorize('outpatient.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Doctor
    {
        return $this->store->query(Doctor::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw OutpatientException::notFound('doctor');
    }
}
