<?php

use Symfony\Component\Finder\Finder;

/*
 * Static checks on the browser app source: no raw HTML injection, no tokens
 * or personal data in browser storage, logical CSS properties (RTL-ready),
 * and every Bangla / English translation key present in both languages.
 */

/** The app's own screens and the business modules' screens (Modules/*\/resources/js). */
function frontendFiles(): Finder
{
    return (new Finder)->files()->in([resource_path('js'), ...glob(base_path('Modules/*/resources/js'), GLOB_ONLYDIR)])->name(['*.vue', '*.js']);
}

it('sees the business modules\' screens too', function () {
    expect(collect(frontendFiles())->contains(fn ($file) => str_contains($file->getPathname(), 'Hrm')))->toBeTrue();
});

it('never renders raw HTML', function () {
    foreach (frontendFiles() as $file) {
        expect($file->getContents())->not->toMatch('/v-html|innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $file->getRelativePathname());
    }
});

it('only touches browser storage through the preferences helper', function () {
    // The offline store (Phase 7-2) is the one other place: everything it writes to
    // IndexedDB is sealed with AES-GCM first (tests/js/offline.test.js checks at rest).
    $offlineStore = ['lib/offline/db.js', 'lib/offline/store.js'];

    foreach (frontendFiles() as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        if ($path === 'lib/storage.js') {
            continue;
        }
        $pattern = in_array($path, $offlineStore, true) ? '/localStorage|sessionStorage/' : '/localStorage|sessionStorage|indexedDB/';
        expect($file->getContents())->not->toMatch($pattern, $path);
    }
});

it('reads typed numbers through the shared helpers, never through a module\'s own copy', function () {
    // One Bangla digit table and one reader for typed numbers (resources/js/lib/numbers.js);
    // modules import them (or @/ui), so a change there reaches every money and number field.
    foreach (frontendFiles() as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        if ($path === 'lib/numbers.js') {
            continue;
        }
        expect($file->getContents())->not->toMatch('/০১২৩৪৫৬৭৮৯|function (cleanNumber|minorToText|latinDigits)\(/', $path);
    }
});

it('frames export and print through the shared menu, never a module\'s own file writer', function () {
    // CSV, XLSX and DOCX are built in lib/pageExport.js only (rules, audit, formula guard).
    foreach (frontendFiles() as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        if ($path === 'lib/pageExport.js') {
            continue;
        }
        expect($file->getContents())->not->toMatch("/from 'write-excel-file|import\\('write-excel-file|from 'docx'|import\\('docx'\\)/", $path);
    }
});

it('draws every module page in the shared page frame, never its own header', function () {
    // PageFrame: title, the loading / error / empty states, export and print in one place (step B).
    $pages = (new Finder)->files()->in(glob(base_path('Modules/*/resources/js/pages'), GLOB_ONLYDIR))->name('*.vue');
    expect(iterator_count($pages))->toBeGreaterThan(150);
    foreach ($pages as $file) {
        expect($file->getContents())->not->toMatch('/\bPageHeader\b/', $file->getRelativePathname());
    }
});

it('uses logical instead of physical left/right utilities', function () {
    foreach (frontendFiles() as $file) {
        expect($file->getContents())->not->toMatch('/\b(?:ml|mr|pl|pr|left|right)-(?:\d|\[|px|auto)|\btext-(?:left|right)\b|\bborder-[lr]\b|\brounded-[lr]-/', $file->getRelativePathname());
    }
});

it('has the same translation keys in Bangla and English', function () {
    $keys = function (array $data, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $out = [...$out, ...(is_array($value) ? $keys($value, $path) : [$path])];
        }

        return $out;
    };

    foreach ([...glob(resource_path('js/locales/en/*.json')), ...glob(base_path('Modules/*/resources/js/locales/en/*.json'))] as $english) {
        $bangla = str_replace(DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR.'bn'.DIRECTORY_SEPARATOR, $english);
        $bangla = str_replace('/en/', '/bn/', $bangla);

        expect(file_exists($bangla))->toBeTrue(basename($english).' has no Bangla file');

        $en = $keys(json_decode(file_get_contents($english), true, flags: JSON_THROW_ON_ERROR));
        $bn = $keys(json_decode(file_get_contents($bangla), true, flags: JSON_THROW_ON_ERROR));
        sort($en);
        sort($bn);

        expect($bn)->toBe($en, basename($english).' keys differ between en and bn');
    }
});
