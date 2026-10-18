/* global __BUILD_ID__ */
import { readTabNote, writeTabNote } from './storage';

/**
 * Keeping open tabs on the release the server has. A tab keeps running the
 * code it loaded, and a new build removes the old files its pages load
 * lazily; so the tab asks now and then which build is out
 * (/build/version.json, never cached) and, when it is newer, the next page
 * change loads the app fresh, quietly. A page of an older build that cannot
 * be fetched any more loads fresh once too.
 */
export const appVersion = { current: typeof __BUILD_ID__ === 'string' ? __BUILD_ID__ : 'dev', stale: false };

const EVERY_MS = 5 * 60 * 1000;
const RELOADED_KEY = 'chunk-reload';

export async function checkVersion() {
    try {
        const response = await fetch('/build/version.json', { cache: 'no-store' });
        if (!response.ok) return;
        const { build } = await response.json();
        if (build && build !== appVersion.current) appVersion.stale = true;
    } catch {
        // Offline or a hiccup: the next check will tell.
    }
}

/** Ask every few minutes and whenever the tab is looked at again (only in a built app). */
export function watchVersion() {
    if (!import.meta.env.PROD) return;
    setInterval(checkVersion, EVERY_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkVersion();
    });
}

/** A file of an older build is gone (a page loaded lazily): load that page fresh, once per address. */
export function reloadForMissingFile(path) {
    if (readTabNote(RELOADED_KEY) === path) return false;
    // Without tab storage it reloads anyway: the browser keeps it from looping on its own page load.
    writeTabNote(RELOADED_KEY, path);
    globalThis.location.assign(path);
    return true;
}

/** Whether an error is a lazily loaded file that is not there any more. */
export function isMissingFile(error) {
    return /dynamically imported module|Importing a module script failed|error loading dynamically|Failed to fetch/i.test(String(error?.message ?? error ?? ''));
}
