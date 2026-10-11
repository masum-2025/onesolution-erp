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
];
