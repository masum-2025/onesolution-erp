<?php

use Symfony\Component\Finder\Finder;

/*
 * Phase 8-2, application layer: static checks over the source, so a new
 * module cannot quietly skip them. Each failure lists file and line.
 */

/**
 * @param  list<string>  $directories
 * @return array<string, string> path => contents
 */
function safetySources(array $directories, string $pattern = '*.php'): array
{
    $existing = array_values(array_filter(array_map('base_path', $directories), 'is_dir'));
    $files = [];

    foreach ((new Finder)->files()->in($existing)->name($pattern)->exclude(['node_modules', 'vendor']) as $file) {
        $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = $file->getContents();
    }

    return $files;
}

/**
 * "path:line" for every line matching $regex.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function safetyLines(array $files, string $regex): array
{
    $found = [];

    foreach ($files as $path => $contents) {
        foreach (preg_split('/\R/', $contents) as $number => $line) {
            if (preg_match($regex, $line) === 1) {
                $found[] = $path.':'.($number + 1);
            }
        }
    }

    return $found;
}

const APP_CODE = ['app', 'Modules', 'routes'];

it('makes every form request reject unknown fields', function () {
    $loose = array_keys(array_filter(
        safetySources(['app', 'Modules']),
        fn (string $contents, string $path) => preg_match('/extends\s+FormRequest\b/', $contents) === 1
            && ! str_ends_with($path, 'Support/Http/StrictFormRequest.php'),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($loose)->toBe([]);
});

it('never passes a whole request into a model', function () {
    expect(safetyLines(safetySources(APP_CODE), '/(\$request|request\(\))->(all|input)\(\s*\)/'))->toBe([]);
});

it('pages every list through the capped page size', function () {
    $uncapped = array_keys(array_filter(
        safetySources(['app', 'Modules']),
        fn (string $contents) => str_contains($contents, '->paginate(') && ! str_contains($contents, 'PerPage::from('),
    ));

    expect($uncapped)->toBe([])
        ->and(safetyLines(safetySources(['app', 'Modules']), '/->(simplePaginate|cursorPaginate|paginate)\(\s*\)/'))->toBe([]);
});

it('uses no raw SQL (bindings through the query builder only)', function () {
    // Unavoidable raw SQL goes behind an interface with MySQL and PostgreSQL versions,
    // and its file is listed here with a reason. None is needed so far.
    $allowed = [];

    $raw = safetyLines(
        safetySources([...APP_CODE, 'database']),
        '/DB::(raw|statement|unprepared|select|insert|update|delete)\(|->(whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw|joinRaw)\(|->raw\(/',
    );

    expect(array_values(array_filter($raw, fn (string $line) => ! in_array(strstr($line, ':', true), $allowed, true))))->toBe([]);
});

it('never locks rows inside an aggregate query (PostgreSQL refuses FOR UPDATE with MAX/COUNT)', function () {
    $locked = [];

    foreach (safetySources([...APP_CODE, 'database']) as $path => $contents) {
        // A builder chain (no statement end in between) that locks, then aggregates in SQL.
        if (preg_match('/->lockForUpdate\(\)[^;]*?->(max|min|sum|count|avg)\(\s*[\'"]/', $contents) === 1) {
            $locked[] = $path;
        }
    }

    expect($locked)->toBe([]);
});

it('never prints unescaped HTML', function () {
    // The scan must actually see the frontend (a wrong pattern would pass silently).
    expect(count(safetySources(['resources/js'], '/\.(vue|js|ts)$/')))->toBeGreaterThan(20);

    expect(safetyLines(safetySources(['resources/views', 'Modules'], '*.blade.php'), '/\{!!/'))->toBe([])
        ->and(safetyLines(safetySources(['resources/js', 'Modules'], '/\.(vue|js|ts)$/'), '/v-html|\.innerHTML\s*=|outerHTML\s*=|insertAdjacentHTML|document\.write\(/'))->toBe([]);
});

it('declares which fields every model accepts', function () {
    $open = [];

    foreach (safetySources(['app', 'Modules']) as $path => $contents) {
        if (preg_match('/class\s+\w+\s+extends\s+(Model|Authenticatable|Pivot)\b/', $contents) !== 1) {
            continue;
        }

        $declared = str_contains($contents, '#[Fillable(')
            || preg_match('/protected\s+\$fillable\s*=\s*\[/', $contents) === 1
            || preg_match('/protected\s+\$guarded\s*=\s*\[\s*\'\*\'\s*\]/', $contents) === 1;

        if (! $declared) {
            $open[] = $path;
        }
    }

    expect($open)->toBe([])
        ->and(safetyLines(safetySources(['app', 'Modules', 'database']), '/\$guarded\s*=\s*\[\s*\]|::unguard\(|->unguard\(/'))->toBe([]);
});

it('keeps uploaded files off the public disk', function () {
    expect(safetyLines(safetySources(['app', 'Modules']), '/storePublicly|(store|storeAs|putFile|putFileAs|disk)\([^)]*[\'"]public[\'"]/'))->toBe([]);
});

it('leaves no debugging output in the code', function () {
    expect(safetyLines(safetySources(APP_CODE), '/(?<![\w>:$])(?<!function )(dd|dump|var_dump|print_r|ray)\(/'))->toBe([]);
});
