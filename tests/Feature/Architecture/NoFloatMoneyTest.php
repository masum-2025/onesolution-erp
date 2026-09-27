<?php

use Symfony\Component\Finder\Finder;

/*
 * No float money anywhere (Phase 6). Money is integer minor units plus a
 * currency code; rates are basis points or decimal strings. This scans the
 * code, so a float that slips in fails the build before it reaches a bill.
 */

/**
 * @return array<string, string> path => contents
 */
function sourceFiles(array $directories, array $names): array
{
    $finder = Finder::create()->files()->in(array_map(fn (string $dir) => base_path($dir), $directories))->name($names)->notPath(['vendor', 'node_modules']);

    $files = [];
    foreach ($finder as $file) {
        $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    return $files;
}

it('stores no money column as float, double or decimal', function () {
    $offenders = [];
    foreach (sourceFiles(['database/migrations', 'tests/Fixtures/migrations'], ['*.php']) as $path => $code) {
        if (preg_match_all('/->(float|double|decimal|unsignedDecimal)\(\s*[\'"]([a-z_]+)[\'"]/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $offenders[] = "{$path}: {$match[1]}('{$match[2]}')";
            }
        }
    }

    expect($offenders)->toBe([], 'Money is integer minor units (bigInteger) plus a currency code.');
});

it('never turns a number into a float in PHP code', function () {
    // Colour contrast is maths on light, not money.
    $allowed = ['Platform/Branding/Contrast.php'];
    $offenders = [];

    foreach (sourceFiles(['app', 'Modules'], ['*.php']) as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }
        if (preg_match('/\(float\)|floatval\(|settype\([^,]+,\s*[\'"](float|double)|:\s*\??float\b|\bfloat\s+\$/', $code, $match)) {
            $offenders[] = "{$path}: {$match[0]}";
        }
    }

    expect($offenders)->toBe([], 'Use integer minor units (Billing\\Money, Payments\\DecimalAmount), never floats.');
});

it('never parses money as a float in the browser app', function () {
    $offenders = [];
    foreach (sourceFiles(['resources/js'], ['*.js', '*.vue']) as $path => $code) {
        if (preg_match('/parseFloat\(|\.toFixed\(/', $code, $match)) {
            $offenders[] = "{$path}: {$match[0]}";
        }
    }

    expect($offenders)->toBe([], 'Convert amounts with string/BigInt math (lib/billing amountToMinor), never floats.');
});

it('catches a float when one appears (the check itself works)', function () {
    expect(preg_match('/\(float\)|floatval\(|:\s*\??float\b|\bfloat\s+\$/', 'function total(): float { return (float) $x; }'))->toBe(1)
        ->and(preg_match('/->(float|double|decimal|unsignedDecimal)\(\s*[\'"]([a-z_]+)[\'"]/', "\$table->decimal('amount', 12, 2);"))->toBe(1)
        ->and(preg_match('/parseFloat\(|\.toFixed\(/', 'const amount = parseFloat(input);'))->toBe(1);
});
