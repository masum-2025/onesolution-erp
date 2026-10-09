import { afterEach, describe, expect, it, vi } from 'vitest';
import { configureLanguages, i18n, initI18n, setLocale, t } from '@/lib/i18n';
import { extraPluralForms, hasMarkup, parseCsv, parseWordingFile, unknownPlaceholders, withPluralForms, wordingCsv } from '@/lib/wording';

/*
 * LANG-1: the wording editor's helpers, and database wording in the
 * browser (fetched only when there is any, under its hash).
 */

describe('wording helpers', () => {
    it('knows the plural forms a language needs beyond English', () => {
        expect(extraPluralForms('en')).toEqual([]);
        expect(extraPluralForms('ar')).toEqual(expect.arrayContaining(['zero', 'two', 'few', 'many']));
    });

    it('adds rows for those forms under a "_other" key', () => {
        const rows = [{ key: 'core.left_other', source: '{count} left', own: null, placeholders: ['count'] }];
        expect(withPluralForms(rows, 'en')).toHaveLength(1);
        const arabic = withPluralForms(rows, 'ar');
        expect(arabic.map((row) => row.key)).toContain('core.left_few');
        expect(arabic.find((row) => row.key === 'core.left_few')).toMatchObject({ source: '{count} left', plural: 'few', own: null });
    });

    it('finds placeholders the original does not have, and markup', () => {
        expect(unknownPlaceholders('{count} of {total}', ['count'])).toEqual(['total']);
        expect(unknownPlaceholders(':count texts', ['count'], 'server')).toEqual([]);
        expect(hasMarkup('Save <b>now</b>')).toBe(true);
        expect(hasMarkup('2 < 3')).toBe(false);
    });

    it('writes and reads back its own CSV, quotes and line breaks included', () => {
        const csv = wordingCsv([{ key: 'core.a', source: 'Hello, you', text: 'হ্যালো "আপনি"', own: 'line 1\nline 2' }]);
        expect(parseCsv(csv)[1]).toEqual(['core.a', 'Hello, you', 'হ্যালো "আপনি"', 'line 1\nline 2']);
        expect(parseWordingFile(csv, 'wording.csv')).toEqual([{ key: 'core.a', value: 'line 1\nline 2' }]);
    });

    it('reads flat, listed and file-shaped JSON', () => {
        expect(parseWordingFile('{"core.actions.save":"Keep"}', 'x.json')).toEqual([{ key: 'core.actions.save', value: 'Keep' }]);
        expect(parseWordingFile('[{"key":"core.a","value":"A"}]', 'x.json')).toEqual([{ key: 'core.a', value: 'A' }]);
        expect(parseWordingFile('{"actions":{"save":"Keep"}}', 'core.json')).toEqual([{ key: 'core.actions.save', value: 'Keep' }]);
    });

    it('says what is wrong with a file it cannot read', () => {
        expect(() => parseWordingFile('{oops', 'x.json')).toThrow(expect.objectContaining({ code: 'bad_file' }));
        expect(() => parseWordingFile('a,b\n1,2', 'x.csv')).toThrow(expect.objectContaining({ code: 'bad_columns' }));
    });
});

describe('database wording in the browser', () => {
    afterEach(async () => {
        vi.restoreAllMocks();
        await configureLanguages({ languages: [], locales: ['en', 'bn'], i18n: { hash: '0', overlays: [] } });
        await setLocale('en', { remember: false });
    });

    it('asks for nothing while there is no wording', async () => {
        const fetch = vi.spyOn(globalThis, 'fetch');
        await initI18n(['en', 'bn'], 'en', { languages: [], i18n: { hash: '0', overlays: [] } });
        expect(fetch).not.toHaveBeenCalled();
        expect(t('core.actions.save')).toBe('Save changes');
    });

    it('puts a level\'s wording over the file, once per hash', async () => {
        const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: { 'core.actions.save': 'Keep it' } }), { status: 200 }));
        await configureLanguages({ locales: ['en', 'bn'], i18n: { hash: 'abc123', overlays: ['en'] } });
        await configureLanguages({ locales: ['en', 'bn'], i18n: { hash: 'abc123', overlays: ['en'] } });

        expect(t('core.actions.save')).toBe('Keep it');
        expect(t('core.actions.edit')).toBe('Edit');
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toBe('/api/i18n/abc123/en');
    });

    it('speaks a database language, with its fallback behind it', async () => {
        vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => new Response(JSON.stringify({ data: url.endsWith('/hi/core') ? { 'core.actions.save': 'सहेजें' } : {} }), { status: 200 }));
        await configureLanguages({
            languages: [
                { code: 'en', name: 'English', direction: 'ltr', source: 'file', fallback: null },
                { code: 'hi', name: 'हिन्दी', direction: 'ltr', source: 'db', fallback: 'en' },
            ],
            locales: ['en', 'hi'],
            i18n: { hash: 'h1', overlays: ['hi'] },
        });
        await setLocale('hi', { remember: false });

        expect(i18n.locale).toBe('hi');
        expect(t('core.actions.save')).toBe('सहेजें');
        // Not translated yet: the fallback's text, never the key.
        expect(t('core.actions.edit')).toBe('Edit');
        expect(document.documentElement.lang).toBe('hi');
    });
});
