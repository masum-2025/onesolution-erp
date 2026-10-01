/**
 * Business modules ship their own screens: Modules/<Module>/resources/js/
 * routes.js exports the module's routes (children of the app shell), each
 * page a lazy chunk. Only these small route lists are part of the first
 * load; a module's pages are fetched when opened. The module's texts live
 * in its own locales folder (see lib/i18n.js).
 */
const definitions = import.meta.glob('../../Modules/*/resources/js/routes.js', { eager: true, import: 'default' });

export const moduleRoutes = Object.values(definitions).flat();
