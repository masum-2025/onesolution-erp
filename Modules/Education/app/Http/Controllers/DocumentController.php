<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Models\Document;
use Modules\Education\Models\DocumentTemplate;
use Modules\Education\Models\Student;
use Modules\Education\Services\DocumentLayout;
use Modules\Education\Services\Documents;
use Modules\Education\Services\DocumentTemplates;
use Modules\Education\Services\Education;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The register of issued documents of the unit in the address and below
 * (education.issue_documents): listed, opened to print (a document that
 * prints private details only with education.view_sensitive), issued to
 * one student or many, and revoked with a reason.
 */
class DocumentController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private Documents $documents, private DocumentTemplates $templates, private DocumentLayout $layout, private EducationPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.issue_documents', $unit);
        $filters = $request->validate([
            'student_id' => ['nullable', 'string', 'size:26'], 'kind' => ['nullable', 'in:'.implode(',', DocumentTemplate::KINDS)],
            'status' => ['nullable', 'in:valid,revoked,expired'], 'q' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $today = $this->education->today($company)->toDateString();
        $page = $this->education->query(Document::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['student_id'] ?? null, fn ($query, $student) => $query->where('student_id', $student))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where('number', 'like', '%'.strtoupper($term).'%'))
            ->when(($filters['status'] ?? null) === 'revoked', fn ($query) => $query->whereNotNull('revoked_at'))
            ->when(($filters['status'] ?? null) === 'valid', fn ($query) => $query->whereNull('revoked_at')->where(fn ($inner) => $inner->whereNull('valid_until')->orWhere('valid_until', '>=', $today)))
            ->when(($filters['status'] ?? null) === 'expired', fn ($query) => $query->whereNull('revoked_at')->where('valid_until', '<', $today))
            ->orderByDesc('issued_on')->orderByDesc('number')->paginate(PerPage::from($request, 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Document $document) => $this->presenter->document($document))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** One document with what it takes to print it: its design, values, images, photo and QR code. */
    public function show(Request $request, string $organization, string $document): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        /** @var Document $found */
        $found = $this->recordIn(Document::class, $unit, $company, $document, 'document');
        Gate::authorize('education.issue_documents', $unit);
        if ($found->has_sensitive) {
            Gate::authorize('education.view_sensitive', $unit);
        }
        $verify = Documents::verifyPath($found);

        return response()->json(['data' => $this->presenter->document($found, [
            'asset_links' => (object) $this->templates->assetLinks($company, $found->snapshot['layout'] ?? []),
            'photo_url' => $this->documents->photoLink($found),
            'verify_path' => $verify,
            'qr' => $this->documents->qr($request->getSchemeAndHttpHost().$verify),
        ])]);
    }

    /** Issue with an active design to students of this unit and below (all or nothing). */
    public function store(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw EducationException::notCompanyUnit();
        }
        Gate::authorize('education.issue_documents', $unit);
        $data = $request->validate([
            'template_id' => ['required', 'string', 'size:26'],
            'student_ids' => ['required', 'array', 'min:1', 'max:'.Documents::MAX_STUDENTS], 'student_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'inputs' => ['sometimes', 'array'], 'inputs.*' => ['nullable', 'string', 'max:1000'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'replace' => ['sometimes', 'boolean'],
            'op_id' => ['nullable', 'string', 'max:36'],
        ]);
        /** @var DocumentTemplate $template */
        $template = $this->education->find(DocumentTemplate::class, $company, $data['template_id'], 'template');
        if ($this->layout->isSensitive($company, $template->layout)) {
            Gate::authorize('education.view_sensitive', $unit);
        }
        $students = $this->education->query(Student::class, $company)->whereKey($data['student_ids'])->whereIn('unit_id', $this->unitIds($unit))->get()->keyBy('id');
        if ($students->count() !== count($data['student_ids'])) {
            throw EducationException::notFound('student');
        }
        $ordered = array_map(fn (string $id) => $students[$id], $data['student_ids']);
        $made = $this->documents->issue($company, $template, $ordered, (array) ($data['inputs'] ?? []), $data['issued_on'] ?? null, (bool) ($data['replace'] ?? false), $data['op_id'] ?? null, $request->user());

        return response()->json([
            'data' => array_map(fn (Document $document) => $this->presenter->document($document), $made),
            'message' => __('education::education.messages.documents_issued', ['count' => count($made)]),
        ], 201);
    }

    public function revoke(Request $request, string $organization, string $document): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Document::class, $unit, $company, $document, 'document');
        Gate::authorize('education.issue_documents', $unit);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return response()->json([
            'data' => $this->presenter->document($this->documents->revoke($company, $found, $data['reason'], $request->user())),
            'message' => __('education::education.messages.document_revoked'),
        ]);
    }

    /** The photo copied at issue, through a signed link only (routes/web.php). */
    public function photo(string $organization, string $document): StreamedResponse
    {
        $company = Organization::query()->find($organization);
        $found = $company === null ? null : Document::inTenantOf($company)->withoutGlobalScopes()->where('organization_id', $organization)->whereKey($document)->first();
        abort_if($found === null || $found->photo_path === null || ! Storage::disk('local')->exists($found->photo_path), 404);

        return Storage::disk('local')->response($found->photo_path, null, [
            'Content-Type' => Storage::disk('local')->mimeType($found->photo_path),
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
