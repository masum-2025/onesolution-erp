<?php

namespace App\Platform\Localization\Services;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\TranslationCatalog;
use App\Platform\Localization\TranslationOverlay;
use App\Platform\Localization\TranslationScope;

/**
 * What the wording editor shows for one level and language: every text with
 * its English original, what the file says, what the levels above say (and
 * which one), and this level's own wording. Searchable and paged.
 */
class TranslationEditor
{
    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    public const FILTERS = ['all', 'missing', 'own', 'changed'];

    public function __construct(
        private TranslationCatalog $catalog,
        private TranslationOverlay $overlay,
        private LanguageRegistry $languages,
    ) {}

    /**
     * @param  list<TranslationScope>  $parents  The levels above, most general first.
     * @param  array<string, array{level: string, name: string|null}>  $labels  Level of each scope key, for "inherited from".
     * @param  array{namespace?: string|null, filter?: string|null, q?: string|null, page?: int|null, per_page?: int|null}  $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(TranslationScope $scope, array $parents, array $labels, Channel $channel, string $locale, array $options = []): array
    {
        $rows = $this->rows($scope, $parents, $labels, $channel, $locale);
        $namespaces = array_values(array_unique(array_map(fn (array $row) => $row['namespace'], $rows)));
        $counts = [
            'all' => count($rows),
            'missing' => count(array_filter($rows, fn (array $row) => $row['effective'] === null)),
            'own' => count(array_filter($rows, fn (array $row) => $row['own'] !== null)),
            'changed' => count(array_filter($rows, fn (array $row) => $row['own'] !== null || $row['inherited'] !== null)),
        ];

        $namespace = $options['namespace'] ?? null;
        $filter = in_array($options['filter'] ?? null, self::FILTERS, true) ? $options['filter'] : 'all';
        $query = mb_strtolower(trim((string) ($options['q'] ?? '')));

        $rows = array_values(array_filter($rows, function (array $row) use ($namespace, $filter, $query) {
            if ($namespace !== null && $namespace !== '' && $row['namespace'] !== $namespace) {
                return false;
            }
            $keep = match ($filter) {
                'missing' => $row['effective'] === null,
                'own' => $row['own'] !== null,
                'changed' => $row['own'] !== null || $row['inherited'] !== null,
                default => true,
            };
            if (! $keep || $query === '') {
                return $keep;
            }

            return str_contains(mb_strtolower($row['key']), $query)
                || str_contains(mb_strtolower((string) $row['source']), $query)
                || str_contains(mb_strtolower((string) $row['effective']), $query);
        }));

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($options['per_page'] ?? self::PER_PAGE)));
        $page = max(1, (int) ($options['page'] ?? 1));

        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'total' => count($rows),
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
                'namespaces' => $namespaces,
                'counts' => $counts,
            ],
        ];
    }

    /**
     * Every row, unpaged (for exporting to a translator's file).
     *
     * @param  list<TranslationScope>  $parents
     * @param  array<string, array{level: string, name: string|null}>  $labels
     * @return list<array<string, mixed>>
     */
    public function rows(TranslationScope $scope, array $parents, array $labels, Channel $channel, string $locale): array
    {
        $sources = $this->catalog->texts($channel);
        $file = $this->languages->isFile($locale) ? $this->catalog->texts($channel, $locale) : [];
        $inherited = $parents === [] ? [] : $this->overlay->sources($parents, $channel, $locale);
        $own = $this->overlay->texts([$scope], $channel, $locale);

        // Plural forms a language needs beyond English's (Arabic "few", …) appear once worded somewhere.
        $keys = array_keys($sources + $inherited + $own);
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $source = $sources[$key] ?? $this->catalog->source($channel, $key);
            if ($source === null) {
                // A key the code no longer has: kept in the table, not shown.
                continue;
            }
            $from = $inherited[$key] ?? null;
            $base = $file[$key] ?? null;
            $ownText = $own[$key] ?? null;

            $rows[] = [
                'key' => $key,
                'namespace' => $this->namespaceOf($channel, $key),
                'source' => $source,
                'file' => $base,
                'inherited' => $from === null ? null : ['value' => $from['value'], ...($labels[$from['scope']] ?? ['level' => 'platform', 'name' => null])],
                'own' => $ownText,
                'effective' => $ownText ?? $from['value'] ?? $base,
                'placeholders' => $channel->placeholders($source),
            ];
        }

        return $rows;
    }

    /** "rules" for a browser key; "validation" or "hrm::module" for a server key. */
    private function namespaceOf(Channel $channel, string $key): string
    {
        if ($channel === Channel::Ui) {
            return TranslationCatalog::namespaceOf($key);
        }

        return str_contains($key, '::') ? strstr($key, '::', true).'::'.strtok(substr($key, strpos($key, '::') + 2), '.') : (strtok($key, '.') ?: $key);
    }
}
