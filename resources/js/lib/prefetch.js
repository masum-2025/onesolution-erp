/**
 * PERF-1: fetch a page's code before it is opened, so a click shows it at once.
 *
 * - prefetchPath(router, path): when someone points at, focuses or touches a
 *   link (they are about to open it). Only the page's own JS chunk is fetched,
 *   never data: the page still asks the API (and its permission) when opened.
 * - prefetchWhenIdle(router, paths): the first few menu pages once the browser
 *   has nothing else to do, skipped on a slow or data-saving connection.
 *
 * A chunk is asked for once; a failed fetch (offline) may be tried again later.
 */

const asked = new Set();

/** Whether the connection is slow or the person asked to save data (then nothing is fetched by itself). */
export function savingData(connection = globalThis.navigator?.connection) {
    if (!connection) return false;
    return connection.saveData === true || ['slow-2g', '2g', '3g'].includes(connection.effectiveType);
}

/** Fetches the lazy page components a path opens (in-app paths only). */
export function prefetchPath(router, path) {
    if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) return;
    let matched;
    try {
        matched = router.resolve(path).matched;
    } catch {
        return;
    }
    for (const record of matched) {
        const component = record.components?.default;
        // A lazy page is a function returning import(); a component object is already loaded.
        if (typeof component !== 'function' || component.prototype || asked.has(component)) continue;
        asked.add(component);
        Promise.resolve()
            .then(component)
            .catch(() => asked.delete(component));
    }
}

/** The first `limit` paths, once the browser is idle (not on a slow or data-saving connection). */
export function prefetchWhenIdle(router, paths, { limit = 4, connection } = {}) {
    if (savingData(connection) || !paths.length) return;
    const run = () => paths.slice(0, limit).forEach((path) => prefetchPath(router, path));
    if (typeof globalThis.requestIdleCallback === 'function') globalThis.requestIdleCallback(run, { timeout: 4000 });
    else setTimeout(run, 2000);
}

/** For tests: forget what was fetched. */
export function resetPrefetch() {
    asked.clear();
}
