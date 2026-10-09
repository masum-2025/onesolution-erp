import { toCsv } from './csv';

/**
 * Helpers of the wording editor (LANG-1), kept free of the screen so they
 * can be tested: plural forms a language needs, reading and writing a
 * translator's file, and checking placeholders before the server does.
 */

/** Plural forms English does not have but this language does ("few" in Arabic, …). */
export function extraPluralForms(locale) {
    try {
        return new Intl.PluralRules(locale).resolvedOptions().pluralCategories.filter((form) => !['one', 'other'].includes(form));
    } catch {
        return [];
    }
}

/**
 * Rows plus a row for each extra plural form of a "…_other" key, with the
 * same English original, unless the server already listed it.
 */
export function withPluralForms(rows, locale) {
    const forms = extraPluralForms(locale);
    if (!forms.length) return rows;
    const known = new Set(rows.map((row) => row.key));
    const result = [];
    for (const row of rows) {
        result.push(row);
        if (!row.key.endsWith('_other')) continue;
        const base = row.key.slice(0, -'_other'.length);
        for (const form of forms) {
            const key = `${base}_${form}`;
            if (known.has(key)) continue;
            result.push({ ...row, key, file: null, inherited: null, own: null, effective: null, plural: form });
        }
    }
    return result;
}

/** Placeholders a text uses that the original does not have: {name} in the browser, :name on the server. */
export function unknownPlaceholders(text, allowed, channel = 'ui') {
    const pattern = channel === 'ui' ? /\{(\w+)\}/g : /:([A-Za-z_][A-Za-z0-9_]*)/g;
    const used = [...String(text ?? '').matchAll(pattern)].map((match) => match[1]);
    return [...new Set(used.filter((name) => !allowed.includes(name)))];
}

/** Whether a text has markup the server would refuse. */
export function hasMarkup(text) {
    return /<\s*[A-Za-z/!?]/.test(String(text ?? ''));
}

/** A translator's file: key, English, current text and this level's own text. */
export function wordingCsv(rows) {
    return toCsv([['key', 'english', 'text', 'own'], ...rows.map((row) => [row.key, row.source, row.text ?? '', row.own ?? ''])]);
}

/** Splits CSV text into rows of cells (quotes, doubled quotes, line breaks inside quotes). */
export function parseCsv(text) {
    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;
    const source = String(text).replace(/^﻿/, '');

    for (let index = 0; index < source.length; index++) {
        const char = source[index];
        if (quoted) {
            if (char === '"' && source[index + 1] === '"') {
                cell += '"';
                index++;
            } else if (char === '"') {
                quoted = false;
            } else {
                cell += char;
            }
        } else if (char === '"') {
            quoted = true;
        } else if (char === ',') {
            row.push(cell);
            cell = '';
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && source[index + 1] === '\n') index++;
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += char;
        }
    }
    if (cell !== '' || row.length) {
        row.push(cell);
        rows.push(row);
    }
    return rows.filter((cells) => cells.some((value) => value !== ''));
}

/** Nested { a: { b: "x" } } -> { "a.b": "x" } (a file-shaped JSON). */
function flatten(object, prefix = '', into = {}) {
    for (const [key, value] of Object.entries(object ?? {})) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value && typeof value === 'object' && !Array.isArray(value)) flatten(value, path, into);
        else if (typeof value === 'string') into[path] = value;
    }
    return into;
}

/**
 * A translator's file read into [{ key, value }]: our CSV (the "own" column,
 * else "text"), a flat or nested JSON object, or a list of { key, value }.
 * A nested JSON of one namespace's file (core.json) gets that namespace in
 * front of its keys. Throws with { code } when nothing can be read.
 */
export function parseWordingFile(text, filename = '') {
    const name = filename.toLowerCase();
    if (name.endsWith('.json') || /^\s*[[{]/.test(text)) {
        let data;
        try {
            data = JSON.parse(text);
        } catch {
            throw Object.assign(new Error('bad_file'), { code: 'bad_file' });
        }
        if (Array.isArray(data)) {
            return data.filter((item) => item && typeof item.key === 'string').map((item) => ({ key: item.key, value: String(item.value ?? '') }));
        }
        const namespace = name.replace(/^.*[\\/]/, '').replace(/\.json$/, '');
        const nested = Object.values(data ?? {}).some((value) => value && typeof value === 'object');
        const flat = flatten(data, nested && namespace && !Object.keys(data).some((key) => key.includes('.')) ? namespace : '');
        return Object.entries(flat).map(([key, value]) => ({ key, value }));
    }

    const [header, ...rows] = parseCsv(text);
    const columns = (header ?? []).map((cell) => cell.trim().toLowerCase());
    const keyAt = columns.indexOf('key');
    const valueAt = columns.indexOf('own') >= 0 && rows.some((cells) => cells[columns.indexOf('own')]) ? columns.indexOf('own') : columns.indexOf('text');
    if (keyAt < 0 || valueAt < 0) throw Object.assign(new Error('bad_columns'), { code: 'bad_columns' });

    // A cell kept as text on export ("'=…") comes back without the guard quote.
    return rows.filter((cells) => cells[keyAt]).map((cells) => ({ key: cells[keyAt].trim(), value: (cells[valueAt] ?? '').replace(/^'(?=[=+\-@\t\r])/, '') }));
}
