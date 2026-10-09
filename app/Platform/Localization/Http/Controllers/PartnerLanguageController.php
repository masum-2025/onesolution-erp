<?php

namespace App\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\Exceptions\LocalizationException;
use App\Platform\Localization\Http\Controllers\Concerns\EditsWording;
use App\Platform\Localization\Http\Requests\ImportTextsRequest;
use App\Platform\Localization\Http\Requests\LanguageRequest;
use App\Platform\Localization\Http\Requests\ListTextsRequest;
use App\Platform\Localization\Http\Requests\SaveTextRequest;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\Models\Language;
use App\Platform\Localization\Services\LanguageService;
use App\Platform\Localization\TranslationScope;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: the partner's own wording for all of its clients, and,
 * for the platform (the house partner's owners), the languages themselves
 * and the platform's texts in them. Partner staff may look; owners change.
 */
class PartnerLanguageController extends Controller
{
    use EditsWording, PartnerConsole;

    public function __construct(private LanguageRegistry $languages, private LanguageService $service) {}

    public function index(): JsonResponse
    {
        $platform = $this->editsPlatform();
        $languages = $this->languages->all();
        if (! $platform) {
            // Drafts are the platform's work in progress.
            $languages = array_filter($languages, fn (array $language) => $language['status'] === 'published');
        }

        return response()->json(['data' => [
            'languages' => array_values(array_map(fn (array $language) => [
                ...$language,
                'completion' => $platform ? $this->service->completion($language['code']) : null,
            ], $languages)),
            'file_languages' => $this->languages->fileCodes(),
            'can_edit' => $this->hasRole(PartnerUserRole::Owner),
            'platform' => $platform,
        ]]);
    }

    public function texts(ListTextsRequest $request): JsonResponse
    {
        [$scope, $parents] = $this->level($request, write: false);

        return $this->listTexts($request, $scope, $parents, $this->labels());
    }

    public function export(ListTextsRequest $request): JsonResponse
    {
        [$scope, $parents] = $this->level($request, write: false);

        return $this->exportTexts($request, $scope, $parents, $this->labels());
    }

    public function update(SaveTextRequest $request): JsonResponse
    {
        [$scope] = $this->level($request, write: true);

        return $this->saveText($request, $scope, null, $this->partner()->getKey());
    }

    public function reset(SaveTextRequest $request): JsonResponse
    {
        [$scope] = $this->level($request, write: true);

        return $this->resetText($request, $scope, null, $this->partner()->getKey());
    }

    public function import(ImportTextsRequest $request): JsonResponse
    {
        [$scope] = $this->level($request, write: true);

        return $this->importTexts($request, $scope, null, $this->partner()->getKey());
    }

    // ── Languages (platform) ──

    public function store(LanguageRequest $request): JsonResponse
    {
        $this->requirePlatform();
        $language = $this->service->create($request->validated(), $request->user());

        return response()->json(['data' => $this->present($language), 'message' => __('languages.messages.language_added')], 201);
    }

    public function updateLanguage(LanguageRequest $request, string $code): JsonResponse
    {
        $this->requirePlatform();
        $language = $this->service->update($this->language($code), $request->validated(), $request->user());

        return response()->json(['data' => $this->present($language), 'message' => __('languages.messages.language_saved')]);
    }

    public function destroy(Request $request, string $code): JsonResponse
    {
        $this->requirePlatform();
        $this->service->remove($this->language($code), $request->user());

        return response()->json(['data' => null, 'message' => __('languages.messages.language_removed')]);
    }

    /**
     * The level asked for (platform only for the house partner's owners) and the levels above it.
     *
     * @return array{0: TranslationScope, 1: list<TranslationScope>}
     */
    private function level(Request $request, bool $write): array
    {
        if ($request->input('level') === 'platform') {
            $this->requirePlatform();

            return [TranslationScope::platform(), []];
        }

        if ($write) {
            $this->requireRole(PartnerUserRole::Owner);
        }

        return [TranslationScope::partner($this->partner()), [TranslationScope::platform()]];
    }

    /** The platform's languages and texts are One Solutions' own: the house partner's owners. */
    private function editsPlatform(): bool
    {
        return (bool) $this->partner()->is_house && $this->hasRole(PartnerUserRole::Owner);
    }

    private function requirePlatform(): void
    {
        if (! $this->editsPlatform()) {
            throw LocalizationException::forbidden();
        }
    }

    private function language(string $code): Language
    {
        $language = Language::query()->where('code', $code)->first();
        if ($language === null) {
            throw $this->languages->isFile($code) ? LocalizationException::fileLanguage() : LocalizationException::unknownLanguage();
        }

        return $language;
    }

    /** @return array<string, mixed> */
    private function present(Language $language): array
    {
        return [
            ...($this->languages->get($language->code) ?? []),
            'code' => $language->code,
            'name' => $language->name,
            'english_name' => $language->english_name,
            'direction' => $language->direction,
            'fallback' => $language->fallback,
            'source' => 'db',
            'status' => $language->status->value,
            'completion' => $this->service->completion($language->code),
        ];
    }

    /** @return array<string, array{level: string, name: string|null}> */
    private function labels(): array
    {
        return [
            'platform' => ['level' => 'platform', 'name' => null],
            TranslationScope::partner($this->partner())->key() => ['level' => 'partner', 'name' => $this->partner()->name],
        ];
    }
}
