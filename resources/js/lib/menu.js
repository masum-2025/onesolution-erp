import { api } from './http';
import { cached } from './cache';

/**
 * Where a module's menu entry leads: its own screen when the app has one
 * (e.g. /online-payments), else the "coming soon" page for the module.
 */
export function menuLink(item, router) {
    const own = item.route ? router.resolve(item.route) : null;
    return own && own.name && !['not-found', 'module-app'].includes(own.name) ? own.fullPath : `/apps/${item.module}/${item.key}`;
}

/**
 * The menu of the current organization (shared with the sidebar's cache):
 * entries and the header's quick actions.
 */
export function loadMenu() {
    return cached('menu', () => api('/api/menu'));
}

/** A module's menu icon name (for its dashboard emblem); null when it has no entry. */
export async function moduleIconName(module) {
    try {
        return (await loadMenu()).data.find((item) => item.module === module)?.icon ?? null;
    } catch {
        return null;
    }
}
