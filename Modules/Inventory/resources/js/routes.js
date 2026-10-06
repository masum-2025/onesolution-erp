/**
 * Inventory screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "inventory" namespace.
 */
const meta = { context: 'organization', ns: ['inventory'], module: 'inventory' };

export default [
    { path: 'inventory', name: 'inventory', component: () => import('./pages/StockPage.vue'), meta },
    { path: 'inventory/stock', name: 'inventory-stock', component: () => import('./pages/StockPage.vue'), meta },
    { path: 'inventory/items', name: 'inventory-items', component: () => import('./pages/ItemsPage.vue'), meta },
    { path: 'inventory/items/:id', name: 'inventory-item', component: () => import('./pages/ItemPage.vue'), meta },
    { path: 'inventory/documents', name: 'inventory-documents', component: () => import('./pages/DocumentsPage.vue'), meta },
    { path: 'inventory/documents/new/:type', name: 'inventory-document-new', component: () => import('./pages/DocumentPage.vue'), meta },
    { path: 'inventory/documents/:id', name: 'inventory-document', component: () => import('./pages/DocumentPage.vue'), meta },
    { path: 'inventory/counts', name: 'inventory-counts', component: () => import('./pages/CountsPage.vue'), meta },
    { path: 'inventory/counts/:id', name: 'inventory-count', component: () => import('./pages/CountPage.vue'), meta },
    { path: 'inventory/expiring', name: 'inventory-expiring', component: () => import('./pages/ExpiringPage.vue'), meta },
    { path: 'inventory/reports', name: 'inventory-reports', component: () => import('./pages/ReportsPage.vue'), meta },
    { path: 'inventory/labels', name: 'inventory-labels', component: () => import('./pages/LabelsPage.vue'), meta },
    { path: 'inventory/warehouses', name: 'inventory-warehouses', component: () => import('./pages/SetupPage.vue'), meta: { ...meta, kind: 'warehouses' } },
    { path: 'inventory/units', name: 'inventory-units', component: () => import('./pages/SetupPage.vue'), meta: { ...meta, kind: 'units' } },
    { path: 'inventory/categories', name: 'inventory-categories', component: () => import('./pages/SetupPage.vue'), meta: { ...meta, kind: 'categories' } },
];
