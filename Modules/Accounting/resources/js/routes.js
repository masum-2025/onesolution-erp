/**
 * Accounting screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "accounting" namespace.
 * Sales and purchases share pages; the route says which side is shown.
 */
const meta = { context: 'organization', ns: ['accounting'], module: 'accounting' };

export default [
    { path: 'accounting', name: 'accounting', component: () => import('./pages/JournalsPage.vue'), meta },
    { path: 'accounting/journals/new', name: 'accounting-journal-new', component: () => import('./pages/JournalFormPage.vue'), meta },
    { path: 'accounting/journals/:id', name: 'accounting-journal', component: () => import('./pages/JournalPage.vue'), meta },
    { path: 'accounting/journals/:id/edit', name: 'accounting-journal-edit', component: () => import('./pages/JournalFormPage.vue'), meta },
    { path: 'accounting/approvals', name: 'accounting-approvals', component: () => import('./pages/ApprovalsPage.vue'), meta },
    { path: 'accounting/accounts', name: 'accounting-accounts', component: () => import('./pages/AccountsPage.vue'), meta },
    { path: 'accounting/reports', name: 'accounting-reports', component: () => import('./pages/ReportsPage.vue'), meta },
    { path: 'accounting/setup', name: 'accounting-setup', component: () => import('./pages/SetupPage.vue'), meta },
    { path: 'accounting/fiscal-years', name: 'accounting-fiscal-years', component: () => import('./pages/FiscalYearsPage.vue'), meta },
    { path: 'accounting/tax-codes', name: 'accounting-tax-codes', component: () => import('./pages/TaxCodesPage.vue'), meta },
    { path: 'accounting/posting-accounts', name: 'accounting-posting-accounts', component: () => import('./pages/PostingAccountsPage.vue'), meta },

    { path: 'accounting/customers', name: 'accounting-customers', component: () => import('./pages/PartiesPage.vue'), meta: { ...meta, role: 'customers' } },
    { path: 'accounting/vendors', name: 'accounting-vendors', component: () => import('./pages/PartiesPage.vue'), meta: { ...meta, role: 'vendors' } },
    { path: 'accounting/parties/:id', name: 'accounting-party', component: () => import('./pages/PartyPage.vue'), meta },
    { path: 'accounting/sales', name: 'accounting-sales', component: () => import('./pages/DocumentsPage.vue'), meta: { ...meta, side: 'sales' } },
    { path: 'accounting/purchases', name: 'accounting-purchases', component: () => import('./pages/DocumentsPage.vue'), meta: { ...meta, side: 'purchases' } },
    { path: 'accounting/documents/new', name: 'accounting-document-new', component: () => import('./pages/DocumentFormPage.vue'), meta },
    { path: 'accounting/documents/:id', name: 'accounting-document', component: () => import('./pages/DocumentPage.vue'), meta },
    { path: 'accounting/documents/:id/edit', name: 'accounting-document-edit', component: () => import('./pages/DocumentFormPage.vue'), meta },
    { path: 'accounting/receipts', name: 'accounting-receipts', component: () => import('./pages/SettlementsPage.vue'), meta: { ...meta, type: 'receipt' } },
    { path: 'accounting/payments', name: 'accounting-payments', component: () => import('./pages/SettlementsPage.vue'), meta: { ...meta, type: 'payment' } },
    { path: 'accounting/settlements/new', name: 'accounting-settlement-new', component: () => import('./pages/SettlementFormPage.vue'), meta },
    { path: 'accounting/settlements/:id', name: 'accounting-settlement', component: () => import('./pages/SettlementPage.vue'), meta },

    // A customer's own invoices in the client's portal (B2B2C): portal members may open these.
    { path: 'portal/invoices', name: 'accounting-portal-invoices', component: () => import('./pages/PortalInvoicesPage.vue'), meta: { ...meta, portal: true } },
    { path: 'portal/invoices/:id', name: 'accounting-portal-invoice', component: () => import('./pages/PortalInvoicePage.vue'), meta: { ...meta, portal: true } },
];
