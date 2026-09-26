import { afterEach, describe, expect, it, vi } from 'vitest';
import { applyBrand, brand, brandText, contrastProblem } from '@/lib/brand';
import { api } from '@/lib/http';

describe('brand contrast (same rules as the server)', () => {
    it('accepts readable brand colors', () => {
        expect(contrastProblem('#2B4C9B')).toBeNull();
        expect(contrastProblem('#0F766E')).toBeNull();
    });

    it('refuses colors text cannot be read on', () => {
        expect(contrastProblem('#7A7A7A')).toBe('text');
    });

    it('refuses colors too light for a white page, unless only used as a fill', () => {
        expect(contrastProblem('#FFF59D')).toBe('surface');
        expect(contrastProblem('#FFF59D', false)).toBeNull();
    });

    it('ignores what is not a color yet (while typing)', () => {
        expect(contrastProblem('#12')).toBeNull();
    });
});

describe('brand texts and tokens', () => {
    it('picks the text in the language, then English, then any', () => {
        applyBrand({ name: 'Acme', primary_color: '#0F766E', login_title: { en: 'Welcome', bn: 'স্বাগতম' }, footer_text: { bn: 'শুধু বাংলা' } });

        expect(brandText('login_title', 'bn')).toBe('স্বাগতম');
        expect(brandText('login_title', 'fr')).toBe('Welcome');
        expect(brandText('footer_text', 'en')).toBe('শুধু বাংলা');
        expect(brandText('tagline', 'en')).toBe('');
    });

    it('applies the partner font and falls back to the default', () => {
        applyBrand({ name: 'Acme', primary_color: '#0F766E', font: "'Hind Siliguri', sans-serif" });
        expect(document.documentElement.style.getPropertyValue('--font-sans')).toBe("'Hind Siliguri', sans-serif");

        applyBrand({ name: 'Acme', primary_color: 'not-a-color', font: null });
        expect(document.documentElement.style.getPropertyValue('--font-sans')).toBe('');
        expect(brand.primary_color).toBe('#2B4C9B');
    });
});

describe('uploads', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('sends files as multipart, never as JSON', async () => {
        const fetch = vi.fn(async () => new Response('{"data":{}}', { status: 200 }));
        vi.stubGlobal('fetch', fetch);

        const body = new FormData();
        body.append('file', new Blob(['x'], { type: 'image/png' }), 'logo.png');
        await api('/api/partner/brand/assets/mark', { method: 'POST', body });

        const [, options] = fetch.mock.calls[0];
        expect(options.body).toBe(body);
        expect(options.headers['Content-Type']).toBeUndefined();
    });
});
