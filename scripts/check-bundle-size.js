// Fails the build when the first page load grows past its budget.
// Counts the entry chunk plus everything it imports statically (not lazy
// screens, which load on demand), gzip-compressed, as a browser receives it.

import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET = { js: 80 * 1024, css: 30 * 1024 };
const ENTRY = 'resources/js/app.js';
const CSS_ENTRY = 'resources/css/app.css';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const gzip = (file) => gzipSync(readFileSync(`public/build/${file}`)).length;

const seen = new Set();
function staticChunks(key) {
    if (seen.has(key) || !manifest[key]) return;
    seen.add(key);
    (manifest[key].imports ?? []).forEach(staticChunks);
}
staticChunks(ENTRY);

const js = [...seen].reduce((sum, key) => sum + gzip(manifest[key].file), 0);
const css = manifest[CSS_ENTRY] ? gzip(manifest[CSS_ENTRY].file) : 0;

const kb = (bytes) => `${(bytes / 1024).toFixed(1)} KB`;
const rows = [
    ['Initial JS (gzip)', js, BUDGET.js],
    ['Initial CSS (gzip)', css, BUDGET.css],
];

let failed = false;
for (const [label, size, budget] of rows) {
    const ok = size <= budget;
    failed ||= !ok;
    console.log(`${ok ? '✓' : '✗'} ${label}: ${kb(size)} / budget ${kb(budget)}`);
}

if (failed) {
    console.error('Bundle budget exceeded. Lazy-load the new code or remove a dependency.');
    process.exit(1);
}
