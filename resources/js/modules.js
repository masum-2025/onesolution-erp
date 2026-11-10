/**
 * Business modules ship their own screens: Modules/<Module>/resources/js/
 * routes.js exports the module's routes (children of the app shell), each
 * page a lazy chunk. The module's texts live in its own locales folder (see
 * lib/i18n.js).
 *
 * FE-1: why route lists are not in the first load. With every module's list
 * built in, each company's browser carried all of them (about 5 KB gzip), on
 * or off, and the first load went past its 80 KB budget. Now a module's list
 * is fetched only when /api/me says the module is on here, and a menu page the
 * person's roles do not give (me.denied_pages, the menu's own test) is left
 * out. A new module needs nothing here: its folder name gives its key.
 * This only shapes the UI; every API call still checks permission, the
 * organization and the module.
 *
 * Pages anyone may open without signing in (meta.outside, like checking a
 * document's QR code) are in public-routes.js and always loaded.
 */
const outside = import.meta.glob('../../Modules/*/resources/js/public-routes.js', { eager: true, import: 'default' });
const loaders = import.meta.glob('../../Modules/*/resources/js/routes.js', { import: 'default' });

export const moduleOutsideRoutes = Object.values(outside).flat();

/** "OnlineOrders" -> "online_orders": a module's key from its folder name. */
export function moduleKeyOf(path) {
    const folder = path.split('/Modules/')[1]?.split('/')[0] ?? '';
    return folder.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase();
}

const byKey = Object.fromEntries(Object.entries(loaders).map(([path, load]) => [moduleKeyOf(path), load]));

const state = { signature: null, removers: [] };

// FE-2: the partner console's screens, only for someone working in a partner's console.
const partnerConsole = () => import('./partnerRoutes.js').then((module) => module.default);

/** What the routes depend on: the context, its modules and the pages left out. */
function signatureOf(me) {
    if (me?.context?.type === 'partner') return `partner:${me.context.id}`;
    if (me?.context?.type !== 'organization') return '';
    return [me.context.id, ...(me.modules ?? []), '|', ...(me.denied_pages ?? [])].join(',');
}

/**
 * Registers the screens of the modules on here (children of the app shell
 * route named `parent`), without the pages this person may not open. Returns
 * whether anything changed (the navigation then resolves its address again).
 */
export async function syncModuleRoutes(router, me, { parent = 'app-shell', modules = byKey, partner = partnerConsole } = {}) {
    const signature = signatureOf(me);
    if (signature === state.signature) return false;

    const inPartner = signature.startsWith('partner:');
    const loaders = inPartner ? { partner } : modules;
    const keys = inPartner ? ['partner'] : signature === '' ? [] : (me.modules ?? []).filter((key) => modules[key]);
    // Nothing registered and nothing to register (signed out, partner console): no change to resolve again.
    if (!keys.length && !state.removers.length) {
        state.signature = signature;
        return false;
    }
    const denied = new Set(me?.denied_pages ?? []);
    const lists = await Promise.all(keys.map((key) => loaders[key]().catch(() => [])));

    state.removers.forEach((remove) => remove());
    state.removers = lists
        .flat()
        .filter((route) => !denied.has(route.path.startsWith('/') ? route.path : `/${route.path}`))
        .map((route) => router.addRoute(parent, route));
    state.signature = signature;

    return true;
}

/** For tests: forget what was registered. */
export function resetModuleRoutes() {
    state.removers = [];
    state.signature = null;
}
