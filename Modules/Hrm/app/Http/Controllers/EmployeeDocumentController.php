<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\EmployeeDocumentRequest;
use Modules\Hrm\Models\EmployeeDocument;
use Modules\Hrm\Services\EmployeeDocuments;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An employee's documents: listing and links need hrm.view, adding and
 * removing hrm.manage. The file itself is served only through a short-lived
 * signed link (download), which is audited.
 */
class EmployeeDocumentController extends Controller
{
    use FindsHrmRecords;

    public function __construct(private EmployeeDocuments $documents, private EmployeePresenter $presenter) {}

    public function index(string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.view', Organization::query()->findOrFail($employee->organization_id));

        return response()->json(['data' => $employee->documents()->orderByDesc('created_at')->get()
            ->map(fn (EmployeeDocument $document) => $this->presenter->document($document))->values()]);
    }

    public function store(EmployeeDocumentRequest $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.manage', Organization::query()->findOrFail($employee->organization_id));

        $document = $this->documents->add($employee, $request->file('file'), $request->safe()->except('file'), $request->user());

        return response()->json(['data' => $this->presenter->document($document)], 201);
    }

    public function link(string $organization, string $employee, string $document): JsonResponse
    {
        $document = $this->document($organization, $employee, $document, 'hrm.view');

        return response()->json(['data' => ['url' => $this->documents->link($document), 'expires_in' => EmployeeDocuments::LINK_MINUTES * 60]]);
    }

    public function destroy(Request $request, string $organization, string $employee, string $document): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $document = $this->document($organization, $employee, $document, 'hrm.manage');

        $this->documents->remove($document, $request->user(), $request->input('reason'));

        return response()->json(['data' => ['removed' => true]]);
    }

    /**
     * The file (signed route in the module's routes/web.php; no sign-in, the
     * signature is the permission and expires in minutes).
     */
    public function download(Request $request, string $organization, string $document): StreamedResponse
    {
        $found = EmployeeDocument::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($document)->first();

        abort_if($found === null || ! Storage::disk('local')->exists($found->file_path), 404);

        $this->documents->recordDownload($found, $request->user());

        // A safe file name (titles may hold any script or quote).
        $name = (Str::slug($found->title) ?: 'document').'.'.pathinfo($found->file_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($found->file_path, $name, [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function document(string $organization, string $employee, string $document, string $permission): EmployeeDocument
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize($permission, Organization::query()->findOrFail($employee->organization_id));

        return $employee->documents()->whereKey($document)->first() ?? throw HrmException::documentNotFound();
    }
}
