/**
 * Every module's signed-in screens, as one list (tests only). The app itself
 * loads a module's list only where it is on (FE-1, resources/js/modules.js);
 * these tests check each module's own routes, so they read them all.
 */
const lists = import.meta.glob('../../../Modules/*/resources/js/routes.js', { eager: true, import: 'default' });

export const moduleRoutes = Object.values(lists).flat();
