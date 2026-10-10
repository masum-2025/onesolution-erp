<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Http\Requests\DocumentTemplateRequest;
use Modules\Education\Models\DocumentAsset;
use Modules\Education\Models\DocumentTemplate;
use Modules\Education\Models\Student;
use Modules\Education\Services\DocumentLayout;
use Modules\Education\Services\Documents;
use Modules\Education\Services\DocumentTemplates;
use Modules\Education\Services\DocumentValues;
use Modules\Education\Services\Education;
use Modules\Education\Services\Students;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Designs of ID cards, certificates and letters, and the images on them:
 * read by people who design (education.manage) or issue them
 * (education.issue_documents), made and changed by people who design.
 * A preview fills a design with a real student's values (private ones only
 * for people allowed to see them).
 */
class DocumentTemplateController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private DocumentTemplates $templates, private DocumentLayout $layout, private EducationPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeRead($unit);
        $filters = $request->validate(['kind' => ['nullable', 'in:'.implode(',', DocumentTemplate::KINDS)], 'status' => ['nullable', 'in:'.implode(',', DocumentTemplate::STATUSES)]]);
        $templates = $this->education->query(DocumentTemplate::class, $company)
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('kind')->orderBy('created_at')->get();

        return response()->json([
            'data' => $templates->map(fn (DocumentTemplate $template) => $this->presenter->template($template))->values(),
            'meta' => [
                'presets' => $this->templates->presets(),
                'made_presets' => $templates->pluck('key')->filter()->values(),
                'can' => ['design' => Gate::allows('education.manage', $unit), 'issue' => Gate::allows('education.issue_documents', $unit)],
            ],
        ]);
    }

    public function show(string $organization, string $template): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeRead($unit);
        $found = $this->education->find(DocumentTemplate::class, $company, $template, 'template');

        return response()->json(['data' => [
            ...$this->presenter->template($found, true),
            'asset_links' => (object) $this->templates->assetLinks($company, $found->layout),
            // Values a design may print: the fixed ones, own fields printed on documents and its questions.
            'placeholders' => $this->layout->placeholders($company, array_column($found->inputs ?? [], 'key')),
            'sensitive_placeholders' => DocumentLayout::SENSITIVE,
        ]]);
    }

    public function store(DocumentTemplateRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.manage', $unit);
        $made = $this->templates->save($company, null, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->template($made, true)], 201);
    }

    public function update(DocumentTemplateRequest $request, string $organization, string $template): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->education->find(DocumentTemplate::class, $company, $template, 'template');
        Gate::authorize('education.manage', $unit);
        $data = $request->validated();
        $changed = $this->templates->save($company, $found, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => [...$this->presenter->template($changed, true), 'asset_links' => (object) $this->templates->assetLinks($company, $changed->layout)]]);
    }

    public function applyPreset(Request $request, string $organization, string $preset): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.manage', $unit);
        $made = $this->templates->applyPreset($company, $preset, $request->user());

        return response()->json(['data' => $this->presenter->template($made), 'message' => __('education::education.messages.document_preset_applied')], $made->wasRecentlyCreated ? 201 : 200);
    }

    /** A design filled with a student's values (as it would print today), for the designer and before issuing. */
    public function preview(Request $request, string $organization, string $template): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->education->find(DocumentTemplate::class, $company, $template, 'template');
        $this->authorizeRead($unit);
        $data = $request->validate(['student_id' => ['required', 'string', 'size:26'], 'inputs' => ['sometimes', 'array'], 'inputs.*' => ['nullable', 'string', 'max:1000']]);
        /** @var Student $student */
        $student = $this->recordIn(Student::class, $unit, $company, $data['student_id'], 'student');
        if ($this->layout->isSensitive($company, $found->layout)) {
            Gate::authorize('education.view_sensitive', $unit);
        }
        $values = app(DocumentValues::class)->for($company, $student, $found->only(['kind', 'locale', 'layout', 'inputs']), (array) ($data['inputs'] ?? []), [
            'number' => '—', 'date' => $this->education->today($company),
        ]);

        return response()->json(['data' => [
            'values' => (object) $values,
            'photo_url' => app(Students::class)->photoLink($student),
            'asset_links' => (object) $this->templates->assetLinks($company, $found->layout),
            'qr' => app(Documents::class)->qr($request->getSchemeAndHttpHost().'/verify'),
        ]]);
    }

    public function assets(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeRead($unit);
        $all = $request->boolean('all');
        $assets = $this->education->query(DocumentAsset::class, $company)->when(! $all, fn ($query) => $query->where('is_active', true))->orderBy('kind')->orderBy('name')->get();

        return response()->json(['data' => $assets->map(fn (DocumentAsset $asset) => $this->presenter->asset($asset, $this->templates->assetLink($asset)))->values()]);
    }

    public function storeAsset(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.manage', $unit);
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', DocumentAsset::KINDS)], 'name' => ['required', 'string', 'min:1', 'max:80'],
            'file' => ['required', 'file', 'max:'.DocumentTemplates::ASSET_MAX_KB],
        ]);
        $asset = $this->templates->addAsset($company, $data['kind'], $data['name'], $request->file('file'), $request->user());

        return response()->json(['data' => $this->presenter->asset($asset, $this->templates->assetLink($asset))], 201);
    }

    public function updateAsset(Request $request, string $organization, string $asset): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->education->find(DocumentAsset::class, $company, $asset, 'asset');
        Gate::authorize('education.manage', $unit);
        $data = $request->validate(['name' => ['sometimes', 'string', 'min:1', 'max:80'], 'is_active' => ['sometimes', 'boolean']]);
        $changed = $this->templates->updateAsset($company, $found, $data, $request->user());

        return response()->json(['data' => $this->presenter->asset($changed, $this->templates->assetLink($changed))]);
    }

    /** An image of the designs, through a signed link only (routes/web.php). */
    public function assetFile(string $organization, string $asset): StreamedResponse
    {
        $company = Organization::query()->find($organization);
        $found = $company === null ? null : DocumentAsset::inTenantOf($company)->withoutGlobalScopes()->where('organization_id', $organization)->whereKey($asset)->first();
        abort_if($found === null || ! Storage::disk('local')->exists($found->path), 404);

        return Storage::disk('local')->response($found->path, null, [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeRead(Organization $unit): void
    {
        if (! Gate::allows('education.manage', $unit) && ! Gate::allows('education.issue_documents', $unit)) {
            Gate::authorize('education.issue_documents', $unit);
        }
    }
}
