/**
 * Compliance screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from this module's "compliance" namespace.
 */
const meta = { context: 'organization', ns: ['compliance'], module: 'compliance' };

export default [
    { path: 'compliance', name: 'compliance', component: () => import('./pages/RegisterPage.vue'), meta },
    { path: 'compliance/renewals', name: 'compliance-renewals', component: () => import('./pages/RenewalsPage.vue'), meta },
    { path: 'compliance/lists', name: 'compliance-lists', component: () => import('./pages/ListsPage.vue'), meta },
    { path: 'compliance/import', name: 'compliance-import', component: () => import('./pages/ImportPage.vue'), meta },
];
