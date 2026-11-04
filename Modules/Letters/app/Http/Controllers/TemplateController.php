<?php

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Http\Controllers\Concerns\FindsPlace;
use Modules\Letters\Http\LettersPresenter;
use Modules\Letters\Http\Requests\AssetRequest;
use Modules\Letters\Http\Requests\IssueRequest;
use Modules\Letters\Http\Requests\TemplateRequest;
use Modules\Letters\Models\LetterAsset;
use Modules\Letters\Models\LetterTemplate;
use Modules\Letters\Services\LetterFields;
use Modules\Letters\Services\LetterLayout;
use Modules\Letters\Services\Letters;
use Modules\Letters\Services\Place;
use Modules\Letters\Services\Templates;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A company's letter designs and images: seen by people who design or issue
 * letters, made and changed with letters.design (salary fields only with
 * letters.salary where the company allows them). "options" gives the
 * editor what it shows: kinds, languages, ready-made designs, the fields
 * by group (salary ones only for people who may use them).
 */
class TemplateController extends Controller
{
    use FindsPlace;

    private const SEES = ['letters.design', 'letters.issue'];

    public function __construct(
        private Place $place,
        private Templates $templates,
        private Letters $letters,
        private LetterFields $fields,
        private LettersPresenter $presenter,
    ) {}

    /** ?locale= gives the fields' labels and examples in a letter's language (the editor's preview); the screen's by default. */
    public function options(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any([...self::SEES, 'letters.view'], $unit), 403);
        $locale = $request->validate(['locale' => ['nullable', 'in:'.implode(',', LanguageRegistry::codes())]])['locale'] ?? app()->getLocale();

        return response()->json(['data' => [
            'kinds' => LetterLayout::KINDS,
            'blocks' => LetterLayout::BLOCKS,
            'locales' => LanguageRegistry::codes(),
            'presets' => $this->templates->presets(),
            'fields' => $this->fields->catalog($company, $locale),
            'salary' => ['enabled' => $this->fields->salaryEnabled($company), 'allowed' => $this->fields->salaryAllowed($company)],
            'max_people' => Letters::MAX_PEOPLE,
            'asset_max_kb' => Templates::ASSET_MAX_KB,
            'can' => [
                'design' => Gate::allows('letters.design', $company),
                'issue' => Gate::allows('letters.issue', $unit),
                'view' => Gate::allows('letters.view', $unit),
            ],
        ]]);
    }

    public function index(string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $order = [LetterTemplate::ACTIVE => 0, LetterTemplate::DRAFT => 1, LetterTemplate::RETIRED => 2];
        $list = $this->place->query(LetterTemplate::class, $company)->orderBy('kind')->orderBy('code')->limit(200)->get()
            ->sortBy(fn (LetterTemplate $template) => $order[$template->status] ?? 3);

        return response()->json(['data' => $list->map(fn (LetterTemplate $template) => $this->presenter->template($template))->values()]);
    }

    public function show(string $organization, string $template): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);

        return response()->json(['data' => $this->presenter->templateFull($company, $this->templateIn($company, $template))]);
    }

    public function store(TemplateRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('letters.design', $company);
        $template = $this->templates->save($company, null, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->templateFull($company, $template)], 201);
    }

    public function update(TemplateRequest $request, string $organization, string $template): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('letters.design', $company);
        $found = $this->templateIn($company, $template);
        $saved = $this->templates->save($company, $found, (int) $request->validated('base_version'), $request->safe()->except('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->templateFull($company, $saved)]);
    }

    public function preset(string $organization, string $preset): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('letters.design', $company);
        $template = $this->templates->applyPreset($company, $preset, request()->user());

        return response()->json(['data' => $this->presenter->templateFull($company, $template)], $template->wasRecentlyCreated ? 201 : 200);
    }

    /** The design filled for one employee (or with examples), in a language; nothing is saved. */
    public function preview(IssueRequest $request, string $organization, string $template): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $found = $this->templateIn($company, $template);
        $employee = $request->validated('employee_id');
        if ($employee !== null) {
            Gate::authorize('letters.issue', $unit);
            if ($found->uses_salary && ! $this->fields->salaryAllowed($company)) {
                $employee = null;
            }
        }
        $content = $this->letters->preview($unit, $company, $found, $request->validated('locale'), $employee, (array) ($request->validated('inputs') ?? []));

        return response()->json(['data' => [
            'content' => $content,
            'images' => $this->templates->assetLinks($company, (array) $content['letterhead'], ['body' => $content['blocks']]),
        ]]);
    }

    public function assets(string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $list = $this->place->query(LetterAsset::class, $company)->orderByDesc('is_active')->orderBy('kind')->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $list->map(fn (LetterAsset $asset) => $this->presenter->asset($asset, $this->templates->assetLink($asset)))->values()]);
    }

    public function storeAsset(AssetRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('letters.design', $company);
        $asset = $this->templates->addAsset($company, $request->validated('kind'), $request->validated('name'), $request->file('file'), $request->user());

        return response()->json(['data' => $this->presenter->asset($asset, $this->templates->assetLink($asset))], 201);
    }

    public function updateAsset(AssetRequest $request, string $organization, string $asset): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('letters.design', $company);
        $found = $this->place->query(LetterAsset::class, $company)->whereKey($asset)->firstOrFail();
        $saved = $this->templates->updateAsset($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->asset($saved, $this->templates->assetLink($saved))]);
    }

    /** An image through its signed link only (the link is the permission; it lasts thirty minutes). */
    public function assetFile(string $organization, string $asset): StreamedResponse
    {
        $company = Organization::query()->find($organization);
        $found = $company === null ? null : LetterAsset::inTenantOf($company)->withoutGlobalScopes()->where('organization_id', $organization)->whereKey($asset)->first();
        abort_if($found === null || ! Storage::disk('local')->exists($found->path), 404);

        return Storage::disk('local')->response($found->path, null, [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
