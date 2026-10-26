/**
 * Expenses screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "expenses" namespace.
 */
const meta = { context: 'organization', ns: ['expenses'], module: 'expenses' };

export default [
    { path: 'expenses/me', name: 'expenses-me', component: () => import('./pages/MyExpensesPage.vue'), meta },
    { path: 'expenses/approvals', name: 'expenses-approvals', component: () => import('./pages/ApprovalsPage.vue'), meta },
    { path: 'expenses/claims', name: 'expenses-claims', component: () => import('./pages/ClaimsPage.vue'), meta },
    { path: 'expenses/categories', name: 'expenses-categories', component: () => import('./pages/CategoriesPage.vue'), meta },

    // An employee's own claims in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/expenses', name: 'expenses-portal', component: () => import('./pages/PortalExpensesPage.vue'), meta: { ...meta, portal: true } },
];
