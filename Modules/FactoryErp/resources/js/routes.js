/**
 * Factory screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "factory_erp" namespace.
 */
const meta = { context: 'organization', ns: ['factory_erp'], module: 'factory_erp' };

export default [
    { path: 'factory', name: 'fac-orders', component: () => import('./pages/OrdersPage.vue'), meta },
    { path: 'factory/orders/:id', name: 'fac-order', component: () => import('./pages/OrderPage.vue'), meta },
    { path: 'factory/styles', name: 'fac-styles', component: () => import('./pages/StylesPage.vue'), meta },
    { path: 'factory/styles/:id', name: 'fac-style', component: () => import('./pages/StylePage.vue'), meta },
    { path: 'factory/fields', name: 'fac-fields', component: () => import('./pages/FieldsPage.vue'), meta },
];
