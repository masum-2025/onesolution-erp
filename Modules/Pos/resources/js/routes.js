/**
 * Point of sale screens (children of the app shell). Each page is its own
 * chunk; texts load from the "pos" namespace.
 */
const meta = { context: 'organization', ns: ['pos'], module: 'pos' };

export default [
    { path: 'pos', name: 'pos', component: () => import('./pages/TillPage.vue'), meta },
    { path: 'pos/sales', name: 'pos-sales', component: () => import('./pages/SalesPage.vue'), meta },
    { path: 'pos/sales/:id', name: 'pos-sale', component: () => import('./pages/ReceiptPage.vue'), meta },
    { path: 'pos/reports', name: 'pos-reports', component: () => import('./pages/ReportsPage.vue'), meta },
    { path: 'pos/shifts', name: 'pos-shifts', component: () => import('./pages/ShiftsPage.vue'), meta },
    { path: 'pos/shifts/:id', name: 'pos-shift', component: () => import('./pages/ShiftPage.vue'), meta },
    { path: 'pos/registers', name: 'pos-registers', component: () => import('./pages/RegistersPage.vue'), meta },
];
