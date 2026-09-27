/**
 * Where a module's menu entry leads: its own screen when the app has one
 * (e.g. /online-payments), else the "coming soon" page for the module.
 */
export function menuLink(item, router) {
    const own = item.route ? router.resolve(item.route) : null;
    return own && own.name && !['not-found', 'module-app'].includes(own.name) ? own.path : `/apps/${item.module}/${item.key}`;
}
