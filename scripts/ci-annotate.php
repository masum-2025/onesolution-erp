<?php

/*
 * CI helper (Phase 10): turns failed tests in a JUnit XML report into GitHub
 * Actions error annotations, so a failure can be read from the run summary
 * without downloading the job log. Test names and assertion messages only;
 * test databases hold no real data.
 *
 *   php scripts/ci-annotate.php junit.xml
 *   php scripts/ci-annotate.php --log vitest.log   (the last lines of a log)
 */

$escape = fn (string $value) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);

if (($argv[1] ?? '') === '--log') {
    $log = (string) @file_get_contents($argv[2] ?? '');
    $lines = array_slice(preg_split('/\R/', preg_replace('/\e\[[0-9;]*m/', '', $log)), -80);
    echo '::error title=Last lines of '.basename($argv[2] ?? 'log').'::'.$escape(mb_substr(implode("\n", $lines), -6000))."\n";
    exit(0);
}

$file = $argv[1] ?? 'junit.xml';

if (! is_file($file)) {
    fwrite(STDERR, "No JUnit report at {$file}; the tests did not get far enough to write one.\n");
    exit(0);
}

$xml = simplexml_load_file($file);
$failures = [];

foreach ($xml->xpath('//testcase[failure or error]') as $case) {
    $problem = $case->failure ?? $case->error;
    $name = trim((string) $case['class'].' > '.(string) $case['name'], ' >');
    $where = (string) ($case['file'] ?? '').(isset($case['line']) ? ':'.$case['line'] : '');
    $text = trim(preg_replace('/\s+/', ' ', (string) $problem));
    $failures[] = "{$name} ({$where}): ".mb_substr($text, 0, 600);
}

if ($failures === []) {
    echo "No failed tests in {$file}.\n";
    exit(0);
}

// GitHub shows at most 10 error annotations per step: five failures each.
foreach (array_slice(array_chunk($failures, 5), 0, 10) as $index => $chunk) {
    echo '::error title=Failed tests '.($index * 5 + 1).'-'.($index * 5 + count($chunk)).' of '.count($failures).'::'
        .$escape(implode("\n\n", $chunk))."\n";
}

echo count($failures)." failed tests.\n";
