<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Enums\Channel;

/**
 * The texts of a chain of levels merged in order: a later (more specific)
 * level wins. Everything here reads cached state only.
 */
class TranslationOverlay
{
    public function __construct(private OverrideStore $store) {}

    /**
     * Short fingerprint of the chain's texts; "0" when no level has any. The
     * browser caches texts under it, so it changes whenever a level does.
     *
     * @param  list<TranslationScope>  $scopes
     */
    public function hash(array $scopes): string
    {
        $parts = [];
        $any = false;
        foreach ($this->store->states($scopes) as $key => $state) {
            $parts[] = "{$key}@{$state['v']}";
            $any = $any || $state['v'] > 0;
        }

        return $any ? substr(sha1(implode('|', $parts)), 0, 16) : '0';
    }

    /**
     * Languages the chain has texts in (so the browser asks only for those).
     *
     * @param  list<TranslationScope>  $scopes
     * @return list<string>
     */
    public function locales(array $scopes, Channel $channel): array
    {
        $locales = [];
        foreach ($this->store->states($scopes) as $state) {
            array_push($locales, ...$state[$channel->value]);
        }
        $locales = array_values(array_unique($locales));
        sort($locales);

        return $locales;
    }

    /**
     * key => text, the most specific level winning; optionally only the keys
     * of one browser namespace.
     *
     * @param  list<TranslationScope>  $scopes
     * @return array<string, string>
     */
    public function texts(array $scopes, Channel $channel, string $locale, ?string $namespace = null): array
    {
        $states = $this->store->states($scopes);
        $texts = [];
        foreach ($scopes as $scope) {
            $state = $states[$scope->key()];
            if (! in_array($locale, $state[$channel->value], true)) {
                continue;
            }
            $texts = array_replace($texts, $this->store->blob($scope, $state['v'], $channel, $locale));
        }

        if ($namespace !== null) {
            $prefix = "{$namespace}.";
            $texts = array_filter($texts, fn (string $key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        }

        return $texts;
    }

    /**
     * Each key's text and the level it comes from, for the editor.
     *
     * @param  list<TranslationScope>  $scopes
     * @return array<string, array{value: string, scope: string}>
     */
    public function sources(array $scopes, Channel $channel, string $locale): array
    {
        $states = $this->store->states($scopes);
        $sources = [];
        foreach ($scopes as $scope) {
            $state = $states[$scope->key()];
            if (! in_array($locale, $state[$channel->value], true)) {
                continue;
            }
            foreach ($this->store->blob($scope, $state['v'], $channel, $locale) as $key => $value) {
                $sources[$key] = ['value' => $value, 'scope' => $scope->key()];
            }
        }

        return $sources;
    }
}
