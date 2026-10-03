/**
 * Accounting screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "accounting" namespace.
 */
const meta = { context: 'organization', ns: ['accounting'], module: 'accounting' };

export default [
    { path: 'accounting', name: 'accounting', component: () => import('./pages/JournalsPage.vue'), meta },
    { path: 'accounting/journals/new', name: 'accounting-journal-new', component: () => import('./pages/JournalFormPage.vue'), meta },
    { path: 'accounting/journals/:id', name: 'accounting-journal', component: () => import('./pages/JournalPage.vue'), meta },
    { path: 'accounting/journals/:id/edit', name: 'accounting-journal-edit', component: () => import('./pages/JournalFormPage.vue'), meta },
    { path: 'accounting/approvals', name: 'accounting-approvals', component: () => import('./pages/JournalsPage.vue'), meta: { ...meta, approvals: true } },
    { path: 'accounting/accounts', name: 'accounting-accounts', component: () => import('./pages/AccountsPage.vue'), meta },
    { path: 'accounting/reports', name: 'accounting-reports', component: () => import('./pages/ReportsPage.vue'), meta },
    { path: 'accounting/setup', name: 'accounting-setup', component: () => import('./pages/SetupPage.vue'), meta },
    { path: 'accounting/fiscal-years', name: 'accounting-fiscal-years', component: () => import('./pages/FiscalYearsPage.vue'), meta },
    { path: 'accounting/posting-accounts', name: 'accounting-posting-accounts', component: () => import('./pages/PostingAccountsPage.vue'), meta },
];
