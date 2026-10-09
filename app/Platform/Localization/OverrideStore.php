<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Models\TranslationOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Overrides as the app reads them: from the cache, never on the hot path
 * from the database.
 *
 *   i18n:v:{scope}                          version + which languages have texts
 *   i18n:blob:{scope}:{version}:{ch}:{loc}  key => text of one level
 *
 * A level's texts are cached under its version, so a change never needs to
 * find and clear old entries: the version moves on and old blobs expire.
 */
class OverrideStore
{
    private const BLOB_TTL_DAYS = 30;

    private const STATE_TTL_MINUTES = 10;

    /**
     * State of each level: its version and the languages it has texts in.
     *
     * @param  list<TranslationScope>  $scopes
     * @return array<string, array{v: int, ui: list<string>, server: list<string>}> by scope key
     */
    public function states(array $scopes): array
    {
        $keys = [];
        foreach ($scopes as $scope) {
            $keys[$scope->key()] = "i18n:v:{$scope->key()}";
        }

        $cached = $keys === [] ? [] : Cache::many(array_values($keys));
        $states = [];
        foreach ($scopes as $scope) {
            $state = $cached[$keys[$scope->key()]] ?? null;
            if (! is_array($state)) {
                $state = $this->load($scope);
                // Not forever: a read racing a change could otherwise keep an old state.
                Cache::put($keys[$scope->key()], $state, now()->addMinutes(self::STATE_TTL_MINUTES));
            }
            $states[$scope->key()] = $state;
        }

        return $states;
    }

    /**
     * One level's texts in a language.
     *
     * @return array<string, string>
     */
    public function blob(TranslationScope $scope, int $version, Channel $channel, string $locale): array
    {
        if ($version === 0) {
            return [];
        }

        return Cache::remember(
            "i18n:blob:{$scope->key()}:{$version}:{$channel->value}:{$locale}",
            now()->addDays(self::BLOB_TTL_DAYS),
            fn () => TranslationOverride::query()
                ->where('scope_type', $scope->type)
                ->where('scope_id', $scope->id)
                ->where('channel', $channel->value)
                ->where('locale', $locale)
                ->pluck('value', 'key')
                ->all(),
        );
    }

    /**
     * Move a level's version on after a change. Call inside the change's
     * transaction: the cached state is dropped once it commits.
     */
    public function bump(TranslationScope $scope): void
    {
        $updated = DB::table('translation_versions')
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->increment('version', 1, ['updated_at' => now()]);

        if ($updated === 0) {
            DB::table('translation_versions')->insert([
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::afterCommit(fn () => Cache::forget("i18n:v:{$scope->key()}"));
    }

    /**
     * @return array{v: int, ui: list<string>, server: list<string>}
     */
    private function load(TranslationScope $scope): array
    {
        $version = (int) DB::table('translation_versions')
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->value('version');

        $locales = ['ui' => [], 'server' => []];
        if ($version > 0) {
            $rows = TranslationOverride::query()
                ->where('scope_type', $scope->type)
                ->where('scope_id', $scope->id)
                ->distinct()
                ->get(['channel', 'locale']);
            foreach ($rows as $row) {
                $locales[$row->channel->value][] = $row->locale;
            }
            sort($locales['ui']);
            sort($locales['server']);
        }

        return ['v' => $version, ...$locales];
    }
}
