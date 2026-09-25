import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError } from '@/lib/http';
import { on } from '@/lib/events';
import { initI18n } from '@/lib/i18n';

function reply(status, body = null, headers = {}) {
    return Promise.resolve(new Response(body === null ? null : JSON.stringify(body), { status, headers }));
}

beforeAll(() => initI18n(['en', 'bn'], 'en'));

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=abc%3D123';
    globalThis.fetch = vi.fn();
});

afterEach(() => vi.restoreAllMocks());

describe('api()', () => {
    it('sends the CSRF token, language and JSON headers with the session cookie', async () => {
        fetch.mockReturnValueOnce(reply(200, { ok: true }));

        await api('/api/me');

        const [url, options] = fetch.mock.calls[0];
        expect(url).toBe('/api/me');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers['X-XSRF-TOKEN']).toBe('abc=123');
        expect(options.headers['X-Locale']).toBe('en');
        expect(options.headers.Authorization).toBeUndefined();
    });

    it('turns validation errors into field messages', async () => {
        fetch.mockReturnValueOnce(reply(422, { message: 'Invalid', errors: { reason: ['Too short.'] } }));

        const error = await api('/x', { method: 'POST', body: {} }).catch((caught) => caught);

        expect(error).toBeInstanceOf(ApiError);
        expect(error.status).toBe(422);
        expect(error.field('reason')).toBe('Too short.');
        expect(error.field('other')).toBeNull();
    });

    it('keeps the stable error code from the server', async () => {
        fetch.mockReturnValueOnce(reply(409, { message: 'Other modules need this one', code: 'dependents_need_confirmation', dependents: ['payroll'] }));

        const error = await api('/x', { method: 'POST' }).catch((caught) => caught);

        expect(error.code).toBe('dependents_need_confirmation');
        expect(error.data.dependents).toEqual(['payroll']);
    });

    it('explains rate limits with the wait time', async () => {
        fetch.mockReturnValueOnce(reply(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '42' }));

        const error = await api('/x').catch((caught) => caught);

        expect(error.retryAfter).toBe(42);
        expect(error.message).toContain('42');
    });

    it('signals a lost session and a lost context', async () => {
        const lost = vi.fn();
        const noContext = vi.fn();
        const offA = on('unauthenticated', lost);
        const offB = on('context-lost', noContext);

        fetch.mockReturnValueOnce(reply(401, { message: 'Unauthenticated.' }));
        await api('/x').catch(() => null);
        fetch.mockReturnValueOnce(reply(403, { message: 'No organization', code: 'no_context' }));
        await api('/x').catch(() => null);

        expect(lost).toHaveBeenCalledOnce();
        expect(noContext).toHaveBeenCalledOnce();
        offA();
        offB();
    });

    it('stays quiet about 401 when asked (session probe)', async () => {
        const lost = vi.fn();
        const off = on('unauthenticated', lost);
        fetch.mockReturnValueOnce(reply(401, {}));

        await api('/api/me', { silentAuth: true }).catch(() => null);

        expect(lost).not.toHaveBeenCalled();
        off();
    });

    it('refreshes an expired CSRF token once and retries', async () => {
        fetch.mockReturnValueOnce(reply(419, {})).mockReturnValueOnce(reply(204)).mockReturnValueOnce(reply(200, { saved: true }));

        const result = await api('/x', { method: 'POST', body: {} });

        expect(result).toEqual({ saved: true });
        expect(fetch.mock.calls[1][0]).toBe('/sanctum/csrf-cookie');
        expect(fetch).toHaveBeenCalledTimes(3);
    });

    it('reports network failures with a helpful message', async () => {
        fetch.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        const error = await api('/x').catch((caught) => caught);

        expect(error.code).toBe('network');
        expect(error.message).toMatch(/internet connection/);
    });

    it('never shows server internals for 500 errors', async () => {
        fetch.mockReturnValueOnce(reply(500, { message: 'SQLSTATE[42S02] table missing', trace: [] }));

        const error = await api('/x').catch((caught) => caught);

        expect(error.message).not.toContain('SQLSTATE');
    });
});
