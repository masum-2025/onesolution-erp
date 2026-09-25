import { beforeAll, describe, expect, it, vi } from 'vitest';
import { has, i18n, initI18n, loadNamespaces, setLocale, t } from '@/lib/i18n';
import { on } from '@/lib/events';

beforeAll(async () => {
    await initI18n(['en', 'bn'], 'en');
    await loadNamespaces(['rules', 'orgs']);
});

describe('translations', () => {
    it('replaces placeholders', () => {
        expect(t('core.context.switched', { name: 'Demo School' })).toBe('You are now working in Demo School.');
    });

    it('picks the plural form and localizes the count', async () => {
        expect(t('rules.preview.changes', { count: 1 })).toBe('1 unit would change');
        expect(t('rules.preview.changes', { count: 3 })).toBe('3 units would change');

        await setLocale('bn', { remember: false });
        expect(t('rules.preview.changes', { count: 3 })).toBe('৩টি ইউনিটে বদলাবে');
        await setLocale('en', { remember: false });
    });

    it('shows the key itself when a translation is missing, never an empty string', () => {
        expect(t('core.does_not_exist')).toBe('core.does_not_exist');
        expect(has('core.does_not_exist')).toBe(false);
    });

    it('switches the page language', async () => {
        await setLocale('bn', { remember: false });
        expect(document.documentElement.lang).toBe('bn');
        expect(t('core.nav.rules')).toBe('নিয়ম');
        await setLocale('en', { remember: false });
        expect(i18n.locale).toBe('en');
    });

    it('announces a language change so server data is fetched again, once', async () => {
        const changed = vi.fn();
        const off = on('locale-changed', changed);

        await setLocale('bn', { remember: false });
        await setLocale('bn', { remember: false });
        await setLocale('en', { remember: false });

        expect(changed.mock.calls).toEqual([['bn'], ['en']]);
        off();
    });

    it('ignores languages the platform does not offer', async () => {
        await setLocale('xx', { remember: false });
        expect(i18n.locale).toBe('en');
    });
});
