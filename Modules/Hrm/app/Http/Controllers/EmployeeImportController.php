<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\ImportFileRequest;
use Modules\Hrm\Models\EmployeeImport;
use Modules\Hrm\Services\EmployeeImporter;

/**
 * Importing employees from a CSV file: columns for the template, check a
 * file, look at the findings, start or cancel. Everything needs hrm.manage
 * at the import's unit (the same as hiring one person).
 */
class EmployeeImportController extends Controller
{
    use FindsHrmRecords;

    public function __construct(private EmployeeImporter $importer, private EmployeePresenter $presenter) {}

    public function columns(Request $request, string $organization): JsonResponse
    {
        $request->validate(['unit_id' => ['nullable', 'string', 'size:26']]);
        $unit = $this->unitIn($this->findVisible($organization), $request->input('unit_id'));
        Gate::authorize('hrm.manage', $unit);

        return response()->json(['data' => $this->importer->columns($unit)]);
    }

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('hrm.manage', $organization);

        return response()->json(['data' => EmployeeImport::query()
            ->whereIn('organization_id', $this->subtreeIds($organization))
            ->latest()->limit(10)->get()
            ->map(fn (EmployeeImport $import) => $this->presenter->import($import))->values()]);
    }

    public function store(ImportFileRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unitIn($this->findVisible($organization), $request->validated('unit_id'));
        Gate::authorize('hrm.manage', $unit);

        $import = $this->importer->check($unit, $request->file('file'), $request->user());

        return response()->json(['data' => $this->presenter->import($import, withRows: true)], 201);
    }

    public function show(string $organization, string $import): JsonResponse
    {
        $import = $this->importIn($this->findVisible($organization), $import);
        Gate::authorize('hrm.manage', $this->unitOf($import));

        return response()->json(['data' => $this->presenter->import($import, withRows: true)]);
    }

    public function start(Request $request, string $organization, string $import): JsonResponse
    {
        $import = $this->importIn($this->findVisible($organization), $import);
        Gate::authorize('hrm.manage', $this->unitOf($import));
        $request->validate(['skip_invalid' => ['sometimes', 'boolean']]);

        $import = $this->importer->start($import, $request->boolean('skip_invalid'), $request->user());

        return response()->json(['data' => $this->presenter->import($import->refresh(), withRows: true)], 202);
    }

    public function destroy(Request $request, string $organization, string $import): JsonResponse
    {
        $import = $this->importIn($this->findVisible($organization), $import);
        Gate::authorize('hrm.manage', $this->unitOf($import));

        $import = $this->importer->cancel($import, $request->user());

        return response()->json(['data' => $this->presenter->import($import->refresh())]);
    }

    private function importIn(Organization $organization, string $id): EmployeeImport
    {
        $import = EmployeeImport::query()->whereKey($id)->first();

        if ($import === null || ! $this->inside($organization, $import->organization_id)) {
            throw HrmException::importNotFound();
        }

        return $import;
    }

    private function unitOf(EmployeeImport $import): Organization
    {
        return Organization::query()->findOrFail($import->organization_id);
    }
}
