/**
 * Fee screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "education_fees" namespace.
 */
const meta = { context: 'organization', ns: ['education_fees'], module: 'education_fees' };

export default [
    { path: 'fees', name: 'fees-counter', component: () => import('./pages/CounterPage.vue'), meta },
    { path: 'fees/students/:id', name: 'fees-student', component: () => import('./pages/StudentFeesPage.vue'), meta },
    { path: 'fees/receipts/:id', name: 'fees-receipt', component: () => import('./pages/ReceiptPage.vue'), meta },
    { path: 'fees/takings', name: 'fees-takings', component: () => import('./pages/TakingsPage.vue'), meta },
    { path: 'fees/approvals', name: 'fees-approvals', component: () => import('./pages/ApprovalsPage.vue'), meta },
    { path: 'fees/runs', name: 'fees-runs', component: () => import('./pages/RunsPage.vue'), meta },
    { path: 'fees/runs/:id', name: 'fees-run', component: () => import('./pages/RunPage.vue'), meta },
    { path: 'fees/bills', name: 'fees-bills', component: () => import('./pages/BillsPage.vue'), meta },
    { path: 'fees/bills/:id', name: 'fees-bill', component: () => import('./pages/BillPage.vue'), meta },
    { path: 'fees/dues', name: 'fees-dues', component: () => import('./pages/DuesPage.vue'), meta },
    { path: 'fees/heads', name: 'fees-heads', component: () => import('./pages/HeadsPage.vue'), meta },
    { path: 'fees/structures', name: 'fees-structures', component: () => import('./pages/StructuresPage.vue'), meta },
    { path: 'fees/books', name: 'fees-books', component: () => import('./pages/BooksPage.vue'), meta },
];
