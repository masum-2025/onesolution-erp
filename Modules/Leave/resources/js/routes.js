/**
 * Leave screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "leave" namespace.
 */
const meta = { context: 'organization', ns: ['leave'], module: 'leave' };

export default [
    { path: 'leave/me', name: 'leave-me', component: () => import('./pages/MyLeavePage.vue'), meta },
    { path: 'leave/requests', name: 'leave-requests', component: () => import('./pages/RequestsPage.vue'), meta },
    { path: 'leave/calendar', name: 'leave-calendar', component: () => import('./pages/CalendarPage.vue'), meta },
    { path: 'leave/balances', name: 'leave-balances', component: () => import('./pages/BalancesPage.vue'), meta },
    { path: 'leave/types', name: 'leave-types', component: () => import('./pages/TypesPage.vue'), meta },

    // An employee's own leave in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/leave', name: 'leave-portal', component: () => import('./pages/PortalLeavePage.vue'), meta: { ...meta, portal: true } },
];
