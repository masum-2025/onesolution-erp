<?php

/*
 * Coverage gate (Phase 11): reads a Clover report and the critical-path
 * targets (tests/critical-coverage.php), prints each area's share and every
 * critical class's uncovered lines, and fails when a target is missed.
 * In GitHub Actions the results are annotations (readable without the log).
 *
 *   php scripts/coverage-gate.php clover.xml
 */

$root = dirname(__DIR__);
$report = $argv[1] ?? 'clover.xml';
$targets = require $root.'/tests/critical-coverage.php';
$github = getenv('GITHUB_ACTIONS') === 'true';

if (! is_file($report)) {
    fwrite(STDERR, "No coverage report at {$report}.\n");
    exit(1);
}

// path relative to the project => [statements, covered, uncovered line numbers]
$files = [];
foreach (simplexml_load_file($report)->xpath('//file') as $file) {
    $path = str_replace('\\', '/', (string) $file['name']);
    $path = ltrim(str_starts_with($path, str_replace('\\', '/', $root)) ? substr($path, strlen(str_replace('\\', '/', $root))) : $path, '/');
    $metrics = $file->metrics;
    $missed = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missed[] = (int) $line['num'];
        }
    }
    $files[$path] = [(int) $metrics['statements'], (int) $metrics['coveredstatements'], $missed];
}

/** "12-14, 20" from [12, 13, 14, 20] */
$ranges = function (array $lines): string {
    $out = [];
    foreach ($lines as $line) {
        $last = array_key_last($out);
        if ($last !== null && $out[$last][1] === $line - 1) {
            $out[$last][1] = $line;
        } else {
            $out[] = [$line, $line];
        }
    }

    return implode(', ', array_map(fn ($r) => $r[0] === $r[1] ? (string) $r[0] : "{$r[0]}-{$r[1]}", $out));
};
$say = function (string $level, string $title, string $text) use ($github) {
    echo $github
        ? "::{$level} title={$title}::".str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text)."\n"
        : strtoupper($level).": {$title}\n{$text}\n\n";
};

$failed = [];
$summary = [];
foreach ($targets['areas'] as $area => $minimum) {
    $statements = $covered = 0;
    foreach ($files as $path => [$s, $c]) {
        if (str_starts_with($path, $area.'/')) {
            $statements += $s;
            $covered += $c;
        }
    }
    $percent = $statements === 0 ? 100 : intdiv($covered * 1000, $statements) / 10;
    $summary[] = sprintf('%-28s %5.1f%% of %d statements (minimum %d%%)', $area, $percent, $statements, $minimum);
    if ($percent < $minimum) {
        $failed[] = "{$area} {$percent}% < {$minimum}%";
    }
}
$say('notice', 'Critical areas', implode("\n", $summary));

$gaps = [];
foreach ($targets['full'] as $path) {
    if (! isset($files[$path])) {
        $gaps[] = "{$path}: not in the report (never loaded by a test)";

        continue;
    }
    [$s, $c, $missed] = $files[$path];
    if ($missed !== []) {
        $gaps[] = "{$path}: {$c}/{$s}; not run: lines ".$ranges($missed);
    }
}
foreach (array_chunk($gaps, 6) as $index => $chunk) {
    $say('error', 'Decision classes not fully covered ('.($index + 1).')', implode("\n", $chunk));
}

if ($failed !== []) {
    $say('error', 'Areas below their minimum', implode("\n", $failed));
}

exit($failed === [] && $gaps === [] ? 0 : 1);
