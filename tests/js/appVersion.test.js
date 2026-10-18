import { afterEach, describe, expect, it, vi } from 'vitest';
import { appVersion, checkVersion, isMissingFile } from '@/lib/appVersion';

describe('keeping open tabs on the release the server has', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        appVersion.stale = false;
    });

    it('notices a newer build, and not its own', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ build: appVersion.current }) })));
        await checkVersion();
        expect(appVersion.stale).toBe(false);
        expect(fetch).toHaveBeenCalledWith('/build/version.json', { cache: 'no-store' });

        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ build: 'newer' }) })));
        await checkVersion();
        expect(appVersion.stale).toBe(true);
    });

    it('stays put when the check cannot be made', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline'); }));
        await checkVersion();
        expect(appVersion.stale).toBe(false);
    });

    it('tells a missing lazily loaded file from other errors', () => {
        expect(isMissingFile(new TypeError('Failed to fetch dynamically imported module: /build/assets/Page-abc.js'))).toBe(true);
        expect(isMissingFile(new Error('Importing a module script failed.'))).toBe(true);
        expect(isMissingFile(new Error('Cannot read properties of undefined'))).toBe(false);
    });
});
