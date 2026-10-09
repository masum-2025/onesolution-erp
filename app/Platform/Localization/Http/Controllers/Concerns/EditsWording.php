<?php

namespace App\Platform\Localization\Http\Controllers\Concerns;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Http\Requests\ImportTextsRequest;
use App\Platform\Localization\Http\Requests\ListTextsRequest;
use App\Platform\Localization\Http\Requests\SaveTextRequest;
use App\Platform\Localization\Services\TranslationEditor;
use App\Platform\Localization\Services\TranslationService;
use App\Platform\Localization\TranslationScope;
use Illuminate\Http\JsonResponse;

/**
 * The wording editor's endpoints, the same for every level. The controller
 * using it decides the level (from the context, never the request), the
 * levels above it, and who may change it.
 */
trait EditsWording
{
    /**
     * @param  list<TranslationScope>  $parents
     * @param  array<string, array{level: string, name: string|null}>  $labels
     */
    protected function listTexts(ListTextsRequest $request, TranslationScope $scope, array $parents, array $labels): JsonResponse
    {
        $locale = (string) $request->validated('locale');
        app(TranslationService::class)->assertLanguage($scope, $locale);

        return response()->json(app(TranslationEditor::class)->page(
            $scope,
            $parents,
            $labels,
            Channel::from($request->validated('channel')),
            $locale,
            $request->safe()->only(['namespace', 'filter', 'q', 'page', 'per_page']),
        ));
    }

    /**
     * Every text of a language, for a translator's file (key, English, current text, own text).
     *
     * @param  list<TranslationScope>  $parents
     * @param  array<string, array{level: string, name: string|null}>  $labels
     */
    protected function exportTexts(ListTextsRequest $request, TranslationScope $scope, array $parents, array $labels): JsonResponse
    {
        $locale = (string) $request->validated('locale');
        app(TranslationService::class)->assertLanguage($scope, $locale);
        $rows = app(TranslationEditor::class)->rows($scope, $parents, $labels, Channel::from($request->validated('channel')), $locale);

        return response()->json(['data' => array_map(fn (array $row) => [
            'key' => $row['key'],
            'source' => $row['source'],
            'text' => $row['effective'],
            'own' => $row['own'],
        ], $rows)]);
    }

    protected function saveText(SaveTextRequest $request, TranslationScope $scope, ?string $organizationId, ?string $partnerId): JsonResponse
    {
        $row = app(TranslationService::class)->save(
            $scope,
            Channel::from($request->validated('channel')),
            (string) $request->validated('locale'),
            (string) $request->validated('key'),
            (string) $request->validated('value'),
            $request->user(),
            $organizationId,
            $partnerId,
        );

        return response()->json(['data' => ['key' => $row->key, 'own' => $row->value, 'version' => $row->version], 'message' => __('languages.messages.saved')]);
    }

    protected function resetText(SaveTextRequest $request, TranslationScope $scope, ?string $organizationId, ?string $partnerId): JsonResponse
    {
        app(TranslationService::class)->reset(
            $scope,
            Channel::from($request->validated('channel')),
            (string) $request->validated('locale'),
            (string) $request->validated('key'),
            $request->user(),
            $organizationId,
            $partnerId,
        );

        return response()->json(['data' => ['key' => $request->validated('key'), 'own' => null], 'message' => __('languages.messages.reset')]);
    }

    protected function importTexts(ImportTextsRequest $request, TranslationScope $scope, ?string $organizationId, ?string $partnerId): JsonResponse
    {
        $count = app(TranslationService::class)->import(
            $scope,
            Channel::from($request->validated('channel')),
            (string) $request->validated('locale'),
            array_column((array) $request->validated('texts'), 'value', 'key'),
            $request->user(),
            $organizationId,
            $partnerId,
        );

        return response()->json(['data' => ['count' => $count], 'message' => __('languages.messages.imported', ['count' => $count])]);
    }
}
